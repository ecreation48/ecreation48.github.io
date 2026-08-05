<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DiscordChannelResource\Pages;
use App\Models\DiscordChannel;
use App\Models\VoiceBroadcast;
use App\Support\GlobalSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DiscordChannelResource extends Resource
{
    protected static ?string $model = DiscordChannel::class;
    protected static ?string $navigationIcon = 'heroicon-o-speaker-wave';
    protected static ?string $navigationLabel = 'Salons vocaux';
    protected static ?string $navigationGroup = 'Discord';

    public static function canViewAny(): bool
    {
        return auth()->user()?->canManageChannels() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canManageChannels() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->canManageChannels() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Salon')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('discord_guild_id')
                        ->label('Serveur')
                        ->relationship('guild', 'name')
                        ->required()
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('discord_id')
                        ->label('Discord ID')
                        ->required()
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('category_discord_id')
                        ->label('Catégorie'),
                    Forms\Components\Select::make('type')
                        ->options(['voice' => 'voice', 'stage' => 'stage'])
                        ->default('voice'),
                    Forms\Components\TextInput::make('user_limit')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                ]),
            Forms\Components\Section::make('Surveillance')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('is_monitored')
                        ->label('Surveiller ce salon')
                        ->default(fn (): bool => (bool) app(GlobalSettings::class)->get('defaults.monitor_new_voice_channels', true))
                        ->live(),
                    Forms\Components\TextInput::make('buffer_seconds')
                        ->numeric()
                        ->minValue(15)
                        ->maxValue(120)
                        ->default(fn (): int => (int) app(GlobalSettings::class)->get('defaults.buffer_seconds', 45)),
                    Forms\Components\TextInput::make('retention_days')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(365)
                        ->default(fn (): int => (int) app(GlobalSettings::class)->get('defaults.retention_days', 30)),
                    Forms\Components\Toggle::make('volume_analysis_enabled')
                        ->default(fn (): bool => (bool) app(GlobalSettings::class)->get('defaults.volume_analysis_enabled', false)),
                    Forms\Components\Toggle::make('transcription_enabled')
                        ->default(fn (): bool => (bool) app(GlobalSettings::class)->get('defaults.transcription_enabled', false)),
                    Forms\Components\Toggle::make('moderation_config.auto_detection_enabled')
                        ->label('Détection live des mots bloqués')
                        ->default(true),
                    Forms\Components\TextInput::make('moderation_config.auto_detection_priority')
                        ->label('Priorité détection live')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(0)
                        ->helperText('Plus la valeur est haute, plus ce salon est transcrit en priorité.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->voiceBased()->activeOnDiscord())
            ->poll('10s')
            ->columns([
                Tables\Columns\TextColumn::make('guild.name')->label('Serveur')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('discord_id')->copyable(),
                Tables\Columns\IconColumn::make('is_monitored')->boolean(),
                Tables\Columns\IconColumn::make('transcription_enabled')
                    ->label('Transcription')
                    ->boolean(),
                Tables\Columns\IconColumn::make('auto_detection_enabled')
                    ->label('Détection live')
                    ->state(fn (DiscordChannel $record): bool => (bool) ($record->moderation_config['auto_detection_enabled'] ?? true))
                    ->boolean(),
                Tables\Columns\TextColumn::make('auto_detection_priority')
                    ->label('Priorité')
                    ->state(fn (DiscordChannel $record): int => (int) ($record->moderation_config['auto_detection_priority'] ?? 0))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw("COALESCE(NULLIF(moderation_config->>'auto_detection_priority', '')::int, 0) {$direction}")),
                Tables\Columns\TextColumn::make('buffer_seconds')->suffix(' s'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_monitored'),
            ])
            ->actions([
                Tables\Actions\Action::make('enableMonitoring')
                    ->label('Activer monitoring')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->visible(fn (DiscordChannel $record): bool => ! $record->is_monitored)
                    ->action(function (DiscordChannel $record): void {
                        $record->update([
                            'is_monitored' => true,
                            'discord_bot_id' => null,
                        ]);

                        Notification::make()
                            ->title('Monitoring activé')
                            ->body('Le salon pourra être pris en charge au prochain cycle du worker.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('disableMonitoring')
                    ->label('Désactiver monitoring')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn (DiscordChannel $record): bool => $record->is_monitored)
                    ->requiresConfirmation()
                    ->action(function (DiscordChannel $record): void {
                        $record->update([
                            'is_monitored' => false,
                            'transcription_enabled' => false,
                            'discord_bot_id' => null,
                        ]);

                        Notification::make()
                            ->title('Monitoring désactivé')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('listenLive')
                    ->label('Écouter en direct')
                    ->icon('heroicon-o-signal')
                    ->color('success')
                    ->visible(fn (DiscordChannel $record): bool => $record->is_monitored && $record->isVoiceBased())
                    ->modalHeading(fn (DiscordChannel $record): string => 'Écoute en direct - '.$record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer')
                    ->modalWidth('3xl')
                    ->modalContent(fn (DiscordChannel $record) => view('filament.resources.discord-channel-resource.components.live-audio-player', ['channel' => $record])),

                Tables\Actions\Action::make('broadcastAudio')
                    ->label('Faire parler')
                    ->icon('heroicon-o-megaphone')
                    ->color('warning')
                    ->visible(fn (DiscordChannel $record): bool => false)
                    ->form([
                        Forms\Components\TextInput::make('title')
                            ->label('Nom du message')
                            ->maxLength(255),
                        Forms\Components\FileUpload::make('audio')
                            ->label('Importer un fichier audio')
                            ->disk('local')
                            ->directory('voice-broadcasts')
                            ->visibility('private')
                            ->preserveFilenames()
                            ->maxSize(51200)
                            ->acceptedFileTypes(['audio/*', 'video/mp4', 'application/octet-stream'])
                            ->helperText('MP3, WAV, OGG, FLAC, M4A/AAC, moins de 50 Mo. Si l’upload échoue à cause de PHP, utilise le chemin local ci-dessous.'),
                        Forms\Components\TextInput::make('local_audio_path')
                            ->label('Chemin local du fichier')
                            ->placeholder('/Users/tomcharbonnel/Downloads/message.mp3')
                            ->helperText('Option de secours : colle le chemin complet du fichier présent sur ce Mac. Cela évite la limite PHP d’upload du serveur local.'),
                    ])
                    ->action(function (DiscordChannel $record, array $data): void {
                        $path = self::resolveBroadcastAudioPath($data);
                        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                        $mimeType = self::mimeTypeForAudioExtension($extension);

                        VoiceBroadcast::query()->create([
                            'discord_bot_id' => $record->guild?->discord_bot_id,
                            'discord_guild_id' => $record->discord_guild_id,
                            'discord_channel_id' => $record->id,
                            'type' => 'file',
                            'status' => 'pending',
                            'storage_path' => $path,
                            'mime_type' => $mimeType,
                            'title' => $data['title'] ?? null,
                            'queued_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Diffusion audio ajoutée')
                            ->body('Le worker la jouera dès que le bot est connecté au salon.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('enableTranscription')
                    ->label('Activer transcription')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->visible(fn (DiscordChannel $record): bool => $record->is_monitored && ! $record->transcription_enabled)
                    ->action(function (DiscordChannel $record): void {
                        $record->update(['transcription_enabled' => true]);

                        Notification::make()
                            ->title('Transcription activée')
                            ->body('Les prochains signalements de ce salon créeront des transcriptions.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('disableTranscription')
                    ->label('Désactiver transcription')
                    ->icon('heroicon-o-document-minus')
                    ->color('gray')
                    ->visible(fn (DiscordChannel $record): bool => $record->is_monitored && $record->transcription_enabled)
                    ->requiresConfirmation()
                    ->action(function (DiscordChannel $record): void {
                        $record->update(['transcription_enabled' => false]);

                        Notification::make()
                            ->title('Transcription désactivée')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('setDetectionPriority')
                    ->label('Priorité détection')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('info')
                    ->visible(fn (DiscordChannel $record): bool => $record->is_monitored && $record->isVoiceBased())
                    ->form([
                        Forms\Components\Toggle::make('auto_detection_enabled')
                            ->label('Détection live active')
                            ->default(fn (DiscordChannel $record): bool => (bool) ($record->moderation_config['auto_detection_enabled'] ?? true)),
                        Forms\Components\TextInput::make('auto_detection_priority')
                            ->label('Priorité')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(fn (DiscordChannel $record): int => (int) ($record->moderation_config['auto_detection_priority'] ?? 0)),
                    ])
                    ->action(function (DiscordChannel $record, array $data): void {
                        $config = $record->moderation_config ?? [];
                        $config['auto_detection_enabled'] = (bool) ($data['auto_detection_enabled'] ?? true);
                        $config['auto_detection_priority'] = (int) ($data['auto_detection_priority'] ?? 0);
                        $record->update(['moderation_config' => $config]);

                        Notification::make()
                            ->title('Priorité mise à jour')
                            ->body('Le worker utilisera ce réglage au prochain cycle.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('enableMonitoringSelected')
                    ->label('Activer le monitoring')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->action(function ($records): void {
                        $count = $records->count();

                        $records->each(function (DiscordChannel $record): void {
                            $record->update([
                                'is_monitored' => true,
                                'discord_bot_id' => null,
                            ]);
                        });

                        Notification::make()
                            ->title('Monitoring activé')
                            ->body($count.' salon(s) mis à jour.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\BulkAction::make('enableTranscriptionSelected')
                    ->label('Activer la transcription')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->action(function ($records): void {
                        $eligible = $records->filter(fn (DiscordChannel $record): bool => $record->is_monitored);
                        $count = $eligible->count();

                        $eligible->each->update(['transcription_enabled' => true]);

                        Notification::make()
                            ->title('Transcription activée')
                            ->body($count.' salon(s) surveillé(s) mis à jour.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\BulkAction::make('disableTranscriptionSelected')
                    ->label('Désactiver la transcription')
                    ->icon('heroicon-o-document-minus')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function ($records): void {
                        $count = $records->count();

                        $records->each->update(['transcription_enabled' => false]);

                        Notification::make()
                            ->title('Transcription désactivée')
                            ->body($count.' salon(s) mis à jour.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\BulkAction::make('unmonitorSelected')
                    ->label('Désélectionner la surveillance')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Désactiver la surveillance des salons sélectionnés ?')
                    ->modalDescription('Les salons sélectionnés ne seront plus surveillés et leur bot affecté sera retiré.')
                    ->action(function ($records): void {
                        $count = $records->count();

                        $records->each->update([
                            'is_monitored' => false,
                            'transcription_enabled' => false,
                            'discord_bot_id' => null,
                        ]);

                        Notification::make()
                            ->title($count.' salon(s) désélectionné(s)')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDiscordChannels::route('/'),
            'create' => Pages\CreateDiscordChannel::route('/create'),
            'edit' => Pages\EditDiscordChannel::route('/{record}/edit'),
        ];
    }

    private static function resolveBroadcastAudioPath(array $data): string
    {
        $uploadedPath = is_array($data['audio'] ?? null) ? reset($data['audio']) : ($data['audio'] ?? null);

        if (filled($uploadedPath)) {
            return (string) $uploadedPath;
        }

        $localPath = trim((string) ($data['local_audio_path'] ?? ''));

        if ($localPath === '') {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.audio' => 'Importe un fichier audio ou renseigne un chemin local.',
            ]);
        }

        if (! is_file($localPath)) {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.local_audio_path' => 'Le fichier local est introuvable.',
            ]);
        }

        $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
        if (! in_array($extension, ['mp3', 'wav', 'ogg', 'opus', 'flac', 'm4a', 'aac'], true)) {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.local_audio_path' => 'Format audio non supporté.',
            ]);
        }

        $directory = storage_path('app/voice-broadcasts');
        File::ensureDirectoryExists($directory);

        $filename = Str::uuid()->toString().'-'.Str::slug(pathinfo($localPath, PATHINFO_FILENAME)).'.'.$extension;
        $target = $directory.'/'.$filename;

        File::copy($localPath, $target);

        return 'voice-broadcasts/'.$filename;
    }

    private static function mimeTypeForAudioExtension(string $extension): string
    {
        return match ($extension) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg', 'opus' => 'audio/ogg',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            default => 'application/octet-stream',
        };
    }
}
