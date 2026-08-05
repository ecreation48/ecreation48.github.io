<?php

namespace App\Filament\Pages;

use App\Models\DiscordBot;
use App\Models\DiscordChannel;
use App\Models\VoiceEvent;
use App\Models\VoiceReport;
use App\Models\VoiceSession;
use App\Models\VoiceTranscript;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class LiveModeration extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bolt';
    protected static ?string $navigationLabel = 'Modération en direct';
    protected static ?string $navigationGroup = 'Modération';
    protected static ?int $navigationSort = 5;
    protected static string $view = 'filament.pages.live-moderation';

    public static function canAccess(): bool
    {
        return auth()->user()?->canManageReports() ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Actualiser')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => null),
        ];
    }

    public function getViewData(): array
    {
        $activeThreshold = now()->subMinutes(2);

        $activeSessions = VoiceSession::query()
            ->with(['bot', 'guild', 'channel', 'members' => fn ($query) => $query->whereNull('left_at')->orderBy('display_name')])
            ->whereNull('ended_at')
            ->where('last_activity_at', '>=', $activeThreshold)
            ->latest('last_activity_at')
            ->get();

        $sessionsByBot = $activeSessions->groupBy('discord_bot_id');
        $sessionsByChannel = $activeSessions->groupBy('discord_channel_id');

        return [
            'stats' => $this->stats($activeSessions, $activeThreshold),
            'bots' => $this->bots($sessionsByBot),
            'channels' => $this->channels($sessionsByChannel),
            'events' => $this->events(),
            'reports' => $this->reports(),
            'transcripts' => $this->transcripts(),
            'generatedAt' => now(),
        ];
    }

    private function stats(Collection $activeSessions, $activeThreshold): array
    {
        return [
            ['label' => 'Bots en ligne', 'value' => DiscordBot::query()->where('is_active', true)->where('connection_status', 'online')->count()],
            ['label' => 'Salons écoutés', 'value' => $activeSessions->pluck('discord_channel_id')->unique()->count()],
            ['label' => 'Utilisateurs vus', 'value' => $activeSessions->sum('member_count')],
            [
                'label' => 'Détection live',
                'value' => DiscordChannel::query()
                    ->voiceBased()
                    ->where('is_monitored', true)
                    ->where(function ($query): void {
                        $query
                            ->whereNull('moderation_config->auto_detection_enabled')
                            ->orWhere('moderation_config->auto_detection_enabled', true);
                    })
                    ->count(),
            ],
            ['label' => 'Signalements auto', 'value' => VoiceReport::query()->where('source', 'blocked_word')->where('reported_at', '>=', now()->subHour())->count()],
            ['label' => 'Transcriptions', 'value' => VoiceTranscript::query()->where('created_at', '>=', $activeThreshold)->count()],
        ];
    }

    private function bots(Collection $sessionsByBot): Collection
    {
        return DiscordBot::query()
            ->with('workerInstance')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(function (DiscordBot $bot) use ($sessionsByBot): array {
                $sessions = $sessionsByBot->get($bot->id, collect());

                return [
                    'name' => $bot->name,
                    'status' => $bot->connection_status ?? 'unknown',
                    'is_active' => $bot->is_active,
                    'last_connected_at' => $bot->last_connected_at,
                    'error_message' => $bot->error_message,
                    'worker' => $bot->workerInstance?->name ?? $bot->worker_instance_id,
                    'sessions' => $sessions,
                ];
            });
    }

    private function channels(Collection $sessionsByChannel): Collection
    {
        return DiscordChannel::query()
            ->with(['guild'])
            ->voiceBased()
            ->activeOnDiscord()
            ->where('is_monitored', true)
            ->orderBy('name')
            ->get()
            ->map(function (DiscordChannel $channel) use ($sessionsByChannel): array {
                $sessions = $sessionsByChannel->get($channel->id, collect());
                $session = $sessions->first();
                $autoDetection = ($channel->moderation_config['auto_detection_enabled'] ?? true) !== false;

                return [
                    'channel' => $channel,
                    'session' => $session,
                    'members' => $session?->members ?? collect(),
                    'is_live' => $session !== null,
                    'auto_detection' => $autoDetection,
                    'priority' => (int) ($channel->moderation_config['auto_detection_priority'] ?? 0),
                ];
            })
            ->sortByDesc(fn (array $row): int => (int) $row['is_live'])
            ->values();
    }

    private function events(): Collection
    {
        return VoiceEvent::query()
            ->with(['guild', 'channel'])
            ->where('occurred_at', '>=', now()->subMinutes(30))
            ->latest('occurred_at')
            ->limit(12)
            ->get();
    }

    private function reports(): Collection
    {
        return VoiceReport::query()
            ->with(['guild', 'channel'])
            ->where('source', 'blocked_word')
            ->latest('reported_at')
            ->limit(8)
            ->get();
    }

    private function transcripts(): Collection
    {
        return VoiceTranscript::query()
            ->with(['report.channel', 'report.guild'])
            ->latest('created_at')
            ->limit(8)
            ->get();
    }
}
