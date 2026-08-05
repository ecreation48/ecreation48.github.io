<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BotGuildAssignment;
use App\Models\DiscordBot;
use App\Models\DiscordChannel;
use App\Models\DiscordGuild;
use App\Models\DiscordRole;
use App\Models\SystemLog;
use App\Models\WorkerInstance;
use App\Services\DiscordGuildSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DiscordBot::query()
                ->where('is_active', true)
                ->get(['id', 'name', 'client_id', 'connection_status', 'restart_requested_at']),
        ]);
    }

    public function credentials(DiscordBot $discordBot): JsonResponse
    {
        abort_unless($discordBot->is_active, 404);

        return response()->json(['data' => [
            'id' => $discordBot->id,
            'token' => $discordBot->token,
            'client_id' => $discordBot->client_id,
        ]]);
    }

    public function assignments(DiscordBot $discordBot): JsonResponse
    {
        abort_unless($discordBot->is_active, 404);

        $channels = DiscordChannel::query()
            ->whereHas('guild', fn ($query) => $query
                ->where('discord_bot_id', $discordBot->id)
                ->orWhereHas('botAssignments', fn ($assignmentQuery) => $assignmentQuery
                    ->where('discord_bot_id', $discordBot->id)
                    ->where('is_active', true)))
            ->where('is_monitored', true)
            ->activeOnDiscord()
            ->voiceBased()
            ->get()
            ->map(function (DiscordChannel $channel): array {
                $guild = DiscordGuild::query()->find($channel->discord_guild_id);

                return [
                    'channel_id' => $channel->id,
                    'channel_discord_id' => $channel->discord_id,
                    'guild_id' => $channel->discord_guild_id,
                    'guild_discord_id' => $guild?->discord_id,
                    'buffer_seconds' => $channel->buffer_seconds,
                    'volume_analysis_enabled' => $channel->volume_analysis_enabled,
                    'transcription_enabled' => $channel->transcription_enabled,
                    'retention_days' => $channel->retention_days,
                    'report_notification_channel_discord_id' => $guild?->report_notification_channel_discord_id,
                    'report_mention_role_discord_ids' => $guild?->report_mention_role_discord_ids ?? [],
                    'moderator_role_discord_id' => $guild?->moderator_role_discord_id,
                    'auto_detection_enabled' => (bool) ($channel->moderation_config['auto_detection_enabled'] ?? true),
                    'auto_detection_priority' => (int) ($channel->moderation_config['auto_detection_priority'] ?? 0),
                ];
            });

        return response()->json(['data' => ['bot_id' => $discordBot->id, 'channels' => $channels->values()]]);
    }

    public function syncGuildChannels(Request $request, DiscordBot $discordBot, string $guildDiscordId, DiscordGuildSyncService $sync): JsonResponse
    {
        abort_unless($discordBot->is_active, 404);

        $data = $request->validate([
            'guild.name' => 'nullable|string|max:512',
            'guild.owner_id' => 'nullable|string|max:255',
            'channels' => 'array',
            'channels.*.id' => 'required|string|max:255',
            'channels.*.name' => 'required|string|max:512',
            'channels.*.type' => 'required|integer',
            'channels.*.parent_id' => 'nullable|string|max:255',
            'channels.*.user_limit' => 'nullable|integer|min:0|max:999',
            'roles' => 'array',
            'roles.*.id' => 'required|string|max:255',
            'roles.*.name' => 'nullable|string|max:512',
            'roles.*.position' => 'nullable|integer',
        ]);

        $guildData = $data['guild'] ?? [];
        $guild = DiscordGuild::query()->firstOrNew(['discord_id' => $guildDiscordId]);

        $guild->forceFill([
            'name' => $guildData['name'] ?? $guild->name ?? $guildDiscordId,
            'owner_discord_id' => $guildData['owner_id'] ?? $guild->owner_discord_id ?? 'unknown',
            'discord_bot_id' => $guild->discord_bot_id ?? $discordBot->id,
            'is_active' => true,
        ])->save();

        BotGuildAssignment::query()->updateOrCreate(
            ['discord_bot_id' => $discordBot->id, 'discord_guild_id' => $guild->id],
            ['is_active' => true],
        );

        foreach ($data['roles'] ?? [] as $role) {
            $roleName = trim((string) ($role['name'] ?? ''));

            DiscordRole::query()->updateOrCreate(
                ['discord_guild_id' => $guild->id, 'discord_id' => $role['id']],
                ['name' => $roleName !== '' ? $roleName : $role['id'], 'position' => $role['position'] ?? 0],
            );
        }

        $sync->syncChannels($guild, collect($data['channels'] ?? []));

        return response()->json(['data' => ['accepted' => true]]);
    }

    public function heartbeat(Request $request, DiscordBot $discordBot): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => 'required|string|max:255',
            'hostname' => 'required|string|max:255',
            'status' => 'required|in:connecting,online,offline,error',
            'version' => 'nullable|string|max:50',
            'error' => 'nullable|string|max:2000',
        ]);

        $previousStatus = $discordBot->connection_status;
        $worker = WorkerInstance::query()->updateOrCreate(
            ['name' => $data['worker_id']],
            [
                'type' => 'discord-manager',
                'hostname' => $data['hostname'],
                'status' => 'online',
                'version' => $data['version'] ?? null,
                'last_heartbeat_at' => now(),
            ],
        );

        $discordBot->update([
            'worker_instance_id' => $worker->id,
            'connection_status' => $data['status'],
            'last_connected_at' => $data['status'] === 'online' ? now() : $discordBot->last_connected_at,
            'last_error_at' => $data['status'] === 'error' ? now() : $discordBot->last_error_at,
            'error_message' => $data['error'] ?? null,
        ]);

        if ($previousStatus !== $data['status'] || $data['status'] === 'error') {
            SystemLog::query()->create([
                'level' => $data['status'] === 'error' ? 'error' : 'info',
                'source' => 'discord-bot',
                'event' => 'bot_'.$data['status'],
                'message' => $discordBot->name.' : '.$this->statusLabel($data['status']),
                'context' => [
                    'bot_id' => $discordBot->id,
                    'bot_name' => $discordBot->name,
                    'worker_id' => $data['worker_id'],
                    'hostname' => $data['hostname'],
                    'error' => $data['error'] ?? null,
                ],
                'occurred_at' => now(),
            ]);
        }

        return response()->json(['data' => ['accepted' => true]]);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'connecting' => 'Connexion en cours',
            'online' => 'Bot en ligne',
            'offline' => 'Bot hors ligne',
            'error' => 'Erreur bot',
            default => $status,
        };
    }
}
