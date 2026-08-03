<?php

namespace App\Filament\Resources\VoiceReportResource\Pages;

use App\Filament\Resources\VoiceReportResource;
use App\Models\ModerationAction;
use App\Models\VoiceAudioClip;
use App\Models\VoiceReport;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditVoiceReport extends EditRecord
{
    protected static string $resource = VoiceReportResource::class;

    protected static string $view = 'filament.resources.voice-report-resource.pages.edit-voice-report';

    public function getTitle(): string
    {
        return 'Signalement vocal';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('review')
                ->label('Prendre en revue')
                ->icon('heroicon-o-eye')
                ->action(function (): void {
                    $this->record->update(['status' => 'under_review']);

                    Notification::make()->title('Signalement en revue')->success()->send();
                }),
            $this->moderationAction('warn', 'Avertissement', 'heroicon-o-exclamation-triangle'),
            $this->moderationAction('timeout', 'Timeout', 'heroicon-o-clock')
                ->form([
                    Forms\Components\TextInput::make('duration_seconds')
                        ->label('Durée en secondes')
                        ->numeric()
                        ->minValue(60)
                        ->default(600),
                    Forms\Components\Textarea::make('reason')
                        ->label('Motif')
                        ->required(),
                ]),
            Actions\ActionGroup::make([
                Actions\Action::make('transcribe')
                    ->label('Relancer la transcription')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->record->audioClips()->exists())
                    ->action(function (): void {
                        $count = $this->enqueueTranscriptions($this->record);

                        $this->record->refresh();

                        Notification::make()
                            ->title($count.' transcription(s) ajoutée(s) à la file')
                            ->body('Le worker Discord les traitera automatiquement.')
                            ->success()
                            ->send();
                    }),
                Actions\Action::make('deleteAudio')
                    ->label('Supprimer audio')
                    ->icon('heroicon-o-speaker-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->record->recording !== null && (auth()->user()?->canApplySanctions() ?? false))
                    ->action(function (): void {
                        $this->record->recording?->delete();
                        $this->record->refresh();

                        Notification::make()->title('Enregistrement audio supprimé')->success()->send();
                    }),
                Actions\Action::make('dismiss')
                    ->label('Classer sans suite')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $this->record->update(['status' => 'dismissed']);
                        $this->createModerationAction('dismiss', 'Classement sans suite', null);

                        Notification::make()->title('Signalement classé sans suite')->success()->send();
                    }),
                Actions\DeleteAction::make()->label('Supprimer')->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false),
            ])
                ->label('Plus')
                ->icon('heroicon-o-ellipsis-horizontal')
                ->button()
                ->color('gray'),
        ];
    }

    private function moderationAction(string $type, string $label, string $icon): Actions\Action
    {
        return Actions\Action::make($type)
            ->label($label)
            ->icon($icon)
            ->visible(fn (): bool => auth()->user()?->canApplySanctions() ?? false)
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('Motif')
                    ->required(),
            ])
            ->action(function (array $data) use ($type, $label): void {
                $this->createModerationAction($type, $data['reason'] ?? $label, $data['duration_seconds'] ?? null);
                $this->record->update(['status' => 'actioned']);

                Notification::make()->title($label.' enregistré')->success()->send();
            });
    }

    private function enqueueTranscriptions(VoiceReport $report): int
    {
        return $report->audioClips()->get()->sum(function (VoiceAudioClip $clip) use ($report): int {
            $report->transcripts()->updateOrCreate(
                [
                    'voice_audio_clip_id' => $clip->id,
                    'reported_user_discord_id' => $clip->reported_user_discord_id,
                ],
                [
                    'status' => 'pending',
                    'text' => null,
                    'language' => null,
                    'confidence' => null,
                    'engine' => null,
                    'duration_ms' => null,
                    'error_message' => null,
                    'started_at' => null,
                    'completed_at' => null,
                ],
            )->segments()->delete();

            return 1;
        });
    }

    private function createModerationAction(string $type, string $reason, ?int $durationSeconds): void
    {
        ModerationAction::query()->create([
            'voice_report_id' => $this->record->id,
            'discord_guild_id' => $this->record->discord_guild_id,
            'target_user_discord_id' => $this->record->reported_user_discord_id,
            'type' => $type,
            'duration_seconds' => $durationSeconds,
            'reason' => $reason,
            'result' => in_array($type, ['warn', 'timeout', 'disconnect'], true) ? 'pending' : 'recorded',
            'actioned_at' => now(),
        ]);
    }
}
