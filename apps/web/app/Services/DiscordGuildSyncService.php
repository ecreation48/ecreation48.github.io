<?php

namespace App\Services;

use App\Models\DiscordChannel;
use App\Models\DiscordGuild;
use App\Models\DiscordMember;
use App\Models\DiscordRole;
use App\Models\BotGuildAssignment;
use App\Models\DiscordBot;
use App\Support\GlobalSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DiscordGuildSyncService
{
    public function __construct(private readonly GlobalSettings $settings) {}

    public function sync(DiscordGuild $guild): void
    {
        $bot = $guild->bot;
        if (! $bot) {
            throw new RuntimeException('Aucun bot principal n’est associé à ce serveur.');
        }

        $client = Http::withToken($bot->token, 'Bot')->acceptJson()->baseUrl('https://discord.com/api/v10')->timeout(15);
        $guildPayload = $client->get("/guilds/{$guild->discord_id}")->throw()->json();

        $guild->forceFill([
            'name' => $guildPayload['name'] ?? $guild->name,
            'icon' => $guildPayload['icon'] ?? $guild->icon,
            'owner_discord_id' => $guildPayload['owner_id'] ?? $guild->owner_discord_id,
        ])->save();

        $ownerId = $guildPayload['owner_id'] ?? null;
        if ($ownerId) {
            $owner = $client->get("/guilds/{$guild->discord_id}/members/{$ownerId}");
            if ($owner->ok()) {
                $member = $owner->json();
                DiscordMember::query()->updateOrCreate(
                    ['discord_guild_id' => $guild->id, 'discord_id' => $ownerId],
                    [
                        'display_name' => $member['nick'] ?? $member['user']['global_name'] ?? $member['user']['username'] ?? $ownerId,
                        'avatar' => $member['user']['avatar'] ?? null,
                        'is_owner' => true,
                    ],
                );
            }
        }

        foreach ($client->get("/guilds/{$guild->discord_id}/roles")->throw()->json() as $role) {
            DiscordRole::query()->updateOrCreate(
                ['discord_guild_id' => $guild->id, 'discord_id' => $role['id']],
                ['name' => $role['name'], 'position' => $role['position'] ?? 0],
            );
        }

        $this->syncChannels($guild, collect($client->get("/guilds/{$guild->discord_id}/channels")->throw()->json()));
        $this->rebalanceMonitoredChannels($guild);
    }

    public function syncChannels(DiscordGuild $guild, Collection $channels): void
    {
        $seenIds = [];
        $now = now();
        $channelDefaults = $this->settings->channelDefaults();
        $monitorNewVoiceChannels = (bool) $this->settings->get('defaults.monitor_new_voice_channels', true);

        foreach ($channels as $channel) {
            $type = (int) ($channel['type'] ?? -1);
            if (! in_array($type, [0, 2, 5, 13], true)) {
                continue;
            }

            $isVoice = in_array($type, [2, 13], true);
            $discordId = (string) $channel['id'];
            $seenIds[] = $discordId;
            $existing = DiscordChannel::query()->where('discord_id', $discordId)->first();
            $isMonitored = $isVoice ? ($existing?->is_monitored ?? $monitorNewVoiceChannels) : false;
            $botId = $isMonitored ? ($existing?->discord_bot_id ?? $guild->discord_bot_id) : null;

            DiscordChannel::query()->updateOrCreate(
                ['discord_id' => $discordId],
                [
                    'discord_guild_id' => $guild->id,
                    'name' => $channel['name'],
                    'category_discord_id' => $channel['parent_id'] ?? null,
                    'type' => $isVoice ? ($type === 13 ? 'stage' : 'voice') : 'text',
                    'user_limit' => $channel['user_limit'] ?? 0,
                    'is_monitored' => $isMonitored,
                    'discord_bot_id' => $botId,
                    'buffer_seconds' => $existing?->buffer_seconds ?? $channelDefaults['buffer_seconds'],
                    'retention_days' => $existing?->retention_days ?? $channelDefaults['retention_days'],
                    'volume_analysis_enabled' => $existing?->volume_analysis_enabled ?? $channelDefaults['volume_analysis_enabled'],
                    'transcription_enabled' => $existing?->transcription_enabled ?? $channelDefaults['transcription_enabled'],
                    'last_synced_at' => $now,
                    'archived_at' => null,
                ],
            );
        }

        if ($seenIds === []) {
            return;
        }

        DiscordChannel::query()
            ->where('discord_guild_id', $guild->id)
            ->whereNotIn('discord_id', $seenIds)
            ->whereNull('archived_at')
            ->update([
                'is_monitored' => false,
                'discord_bot_id' => null,
                'archived_at' => $now,
            ]);
    }

    public function rebalanceMonitoredChannels(DiscordGuild $guild): void
    {
        $botIds = BotGuildAssignment::query()
            ->where('discord_guild_id', $guild->id)
            ->where('is_active', true)
            ->whereHas('bot', fn ($query) => $query->where('is_active', true))
            ->orderBy('created_at')
            ->pluck('discord_bot_id')
            ->values();

        if ($botIds->isEmpty() && filled($guild->discord_bot_id)) {
            $botIds = DiscordBot::query()
                ->whereKey($guild->discord_bot_id)
                ->where('is_active', true)
                ->pluck('id')
                ->values();
        }

        if ($botIds->isEmpty()) {
            return;
        }

        DiscordChannel::query()
            ->where('discord_guild_id', $guild->id)
            ->voiceBased()
            ->activeOnDiscord()
            ->where('is_monitored', true)
            ->orderBy('name')
            ->get()
            ->values()
            ->each(function (DiscordChannel $channel, int $index) use ($botIds): void {
                $channel->forceFill(['discord_bot_id' => $botIds[$index % $botIds->count()]])->save();
            });
    }
}
