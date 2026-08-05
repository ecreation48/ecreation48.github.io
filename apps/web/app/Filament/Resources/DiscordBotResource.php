<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DiscordBotResource\Pages;
use App\Models\DiscordBot;
use App\Models\SystemLog;
use App\Services\LocalDiscordWorkerService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DiscordBotResource extends Resource
{
    protected static ?string $model = DiscordBot::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'Bots Discord';

    public static function canViewAny(): bool
    {
        return auth()->user()?->canManageConfiguration() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canManageConfiguration() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->canManageConfiguration() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->canManageConfiguration() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nom')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('client_id')
                ->label('Client ID')
                ->required()
                ->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('token')
                ->label('Token')
                ->password()
                ->revealable()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText('Le token est chiffré et ne sera plus affiché.'),
            Forms\Components\Toggle::make('is_active')
                ->label('Actif'),
            Forms\Components\KeyValue::make('default_config')
                ->label('Configuration par défaut'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['workerInstance', 'activeVoiceSessions.channel', 'activeVoiceSessions.guild']))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->description(fn (DiscordBot $record): string => self::botDiagnostic($record)),
                Tables\Columns\TextColumn::make('client_id')
                    ->label('Client ID')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),
                Tables\Columns\TextColumn::make('connection_status')
                    ->label('Connexion')
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->badge()
                    ->color(fn (DiscordBot $record, string $state): string => self::statusColor($record, $state)),
                Tables\Columns\TextColumn::make('current_voice_channels')
                    ->label('Salons actuels')
                    ->state(fn (DiscordBot $record): string => self::currentVoiceChannels($record))
                    ->description(fn (DiscordBot $record): ?string => self::currentVoiceChannelsActivity($record))
                    ->placeholder('Aucun salon')
                    ->wrap(),
                Tables\Columns\TextColumn::make('workerInstance.name')
                    ->label('Worker')
                    ->placeholder('Aucun worker')
                    ->description(fn (DiscordBot $record): ?string => $record->workerInstance?->hostname)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('workerInstance.status')
                    ->label('État worker')
                    ->formatStateUsing(fn (?string $state): string => $state === 'online' ? 'Actif' : 'Inactif')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'online' ? 'success' : 'gray')
                    ->placeholder('Inconnu'),
                Tables\Columns\TextColumn::make('workerInstance.last_heartbeat_at')
                    ->label('Dernier heartbeat')
                    ->since()
                    ->placeholder('Jamais')
                    ->description(fn (DiscordBot $record): ?string => $record->workerInstance?->last_heartbeat_at?->timezone(config('app.timezone'))->format('d/m/Y H:i:s')),
                Tables\Columns\TextColumn::make('last_connected_at')
                    ->label('Dernière connexion')
                    ->dateTime()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Erreur')
                    ->limit(70)
                    ->placeholder('Aucune')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('restart_requested_at')
                    ->label('Relance demandée')
                    ->since()
                    ->placeholder('Aucune')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\Action::make('startWorker')
                    ->label('Démarrer worker')
                    ->icon('heroicon-o-command-line')
                    ->color('success')
                    ->action(function (): void {
                        $worker = app(LocalDiscordWorkerService::class);

                        try {
                            $pid = $worker->start();

                            Notification::make()
                                ->title('Worker Discord démarré')
                                ->body('PID '.$pid.' - logs : '.$worker->logFile())
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Démarrage impossible')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('restartWorker')
                    ->label('Relancer worker')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $worker = app(LocalDiscordWorkerService::class);

                        try {
                            $pid = $worker->restart();

                            Notification::make()
                                ->title('Worker Discord relancé')
                                ->body('PID '.$pid)
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Relance impossible')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('stopWorker')
                    ->label('Stopper worker')
                    ->icon('heroicon-o-power')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $worker = app(LocalDiscordWorkerService::class);
                        $worker->stop();

                        Notification::make()
                            ->title('Worker Discord stoppé')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('workerConsole')
                    ->label('Console worker')
                    ->icon('heroicon-o-document-text')
                    ->modalHeading('Console du worker Discord')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer')
                    ->modalContent(function () {
                        $worker = app(LocalDiscordWorkerService::class);

                        return view('filament.resources.discord-bot-resource.components.worker-console', [
                        'isRunning' => $worker->isRunning(),
                        'pid' => $worker->pid(),
                        'logFile' => $worker->logFile(),
                        'logs' => $worker->tail(),
                        ]);
                    }),
                Tables\Actions\Action::make('startAll')
                    ->label('Démarrer tous')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $bots = DiscordBot::query()->get();

                        $bots->each(function (DiscordBot $bot): void {
                            $bot->update([
                                'is_active' => true,
                                'connection_status' => 'connecting',
                                'restart_requested_at' => now(),
                                'error_message' => null,
                            ]);

                            self::botLog($bot, 'info', 'bot_start_requested', 'Démarrage demandé');
                        });

                        Notification::make()->title($bots->count().' bot(s) à démarrer')->success()->send();
                    }),
                Tables\Actions\Action::make('stopAll')
                    ->label('Stopper tous')
                    ->icon('heroicon-o-stop')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $bots = DiscordBot::query()->get();

                        $bots->each(function (DiscordBot $bot): void {
                            $bot->update([
                                'is_active' => false,
                                'connection_status' => 'offline',
                                'restart_requested_at' => null,
                            ]);

                            self::botLog($bot, 'warning', 'bot_stop_requested', 'Arrêt demandé');
                        });

                        Notification::make()->title($bots->count().' bot(s) stoppé(s)')->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('start')
                    ->label('Démarrer')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (DiscordBot $record): bool => ! $record->is_active)
                    ->action(function (DiscordBot $record): void {
                        $record->update([
                            'is_active' => true,
                            'connection_status' => 'connecting',
                            'restart_requested_at' => now(),
                            'error_message' => null,
                        ]);

                        self::botLog($record, 'info', 'bot_start_requested', 'Démarrage demandé');

                        Notification::make()
                            ->title('Démarrage demandé')
                            ->body($record->name.' sera démarré par le worker.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('stop')
                    ->label('Stopper')
                    ->icon('heroicon-o-stop')
                    ->color('danger')
                    ->visible(fn (DiscordBot $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (DiscordBot $record): void {
                        $record->update([
                            'is_active' => false,
                            'connection_status' => 'offline',
                            'restart_requested_at' => null,
                        ]);

                        self::botLog($record, 'warning', 'bot_stop_requested', 'Arrêt demandé');

                        Notification::make()
                            ->title('Arrêt demandé')
                            ->body($record->name.' sera stoppé par le worker.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('restart')
                    ->label('Relancer')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (DiscordBot $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (DiscordBot $record): void {
                        $record->update([
                            'restart_requested_at' => now(),
                            'connection_status' => 'connecting',
                            'error_message' => null,
                        ]);

                        self::botLog($record, 'info', 'bot_restart_requested', 'Relance demandée');

                        Notification::make()
                            ->title('Relance demandée')
                            ->body($record->name.' sera relancé par le worker.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('logs')
                    ->label('Logs')
                    ->icon('heroicon-o-document-text')
                    ->modalHeading(fn (DiscordBot $record): string => 'Logs - '.$record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer')
                    ->modalContent(fn (DiscordBot $record) => view('filament.resources.discord-bot-resource.components.bot-logs', ['bot' => $record])),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('startSelected')
                    ->label('Démarrer la sélection')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->action(function ($records): void {
                        $records->each(function (DiscordBot $record): void {
                            $record->update([
                                'is_active' => true,
                                'connection_status' => 'connecting',
                                'restart_requested_at' => now(),
                                'error_message' => null,
                            ]);

                            self::botLog($record, 'info', 'bot_start_requested', 'Démarrage demandé');
                        });

                        Notification::make()->title($records->count().' bot(s) à démarrer')->success()->send();
                    }),
                Tables\Actions\BulkAction::make('stopSelected')
                    ->label('Stopper la sélection')
                    ->icon('heroicon-o-stop')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($records): void {
                        $records->each(function (DiscordBot $record): void {
                            $record->update([
                                'is_active' => false,
                                'connection_status' => 'offline',
                                'restart_requested_at' => null,
                            ]);

                            self::botLog($record, 'warning', 'bot_stop_requested', 'Arrêt demandé');
                        });

                        Notification::make()->title($records->count().' bot(s) stoppé(s)')->success()->send();
                    }),
                Tables\Actions\BulkAction::make('restartSelected')
                    ->label('Relancer la sélection')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function ($records): void {
                        $records->each(function (DiscordBot $record): void {
                            $record->update([
                                'is_active' => true,
                                'restart_requested_at' => now(),
                                'connection_status' => 'connecting',
                                'error_message' => null,
                            ]);

                            self::botLog($record, 'info', 'bot_restart_requested', 'Relance demandée');
                        });

                        Notification::make()->title($records->count().' bot(s) à relancer')->success()->send();
                    }),
            ]);
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'online' => 'En ligne',
            'connecting' => 'Connexion',
            'offline' => 'Hors ligne',
            'error' => 'Erreur',
            default => $status,
        };
    }

    private static function statusColor(DiscordBot $bot, string $status): string
    {
        if (self::isStaleConnecting($bot)) {
            return 'danger';
        }

        return match ($status) {
            'online' => 'success',
            'error' => 'danger',
            'connecting' => 'warning',
            default => 'gray',
        };
    }

    private static function botDiagnostic(DiscordBot $bot): string
    {
        if (! $bot->is_active) {
            return 'Bot arrêté';
        }

        if ($bot->connection_status === 'online') {
            return 'Connecté et surveillé par le worker';
        }

        if ($bot->connection_status === 'error') {
            return $bot->error_message ? 'Erreur : '.$bot->error_message : 'Erreur de connexion';
        }

        if (self::isStaleConnecting($bot)) {
            return 'Connexion trop longue : vérifie que la console du worker Discord tourne';
        }

        if ($bot->connection_status === 'connecting') {
            return 'En attente du prochain heartbeat du worker';
        }

        return 'En attente de démarrage';
    }

    private static function currentVoiceChannels(DiscordBot $bot): string
    {
        $sessions = $bot->activeVoiceSessions;

        if ($sessions->isEmpty()) {
            return 'Aucun salon';
        }

        return $sessions
            ->map(function ($session): string {
                $guild = $session->guild?->name ?? 'Serveur inconnu';
                $channel = $session->channel?->name ?? 'Salon inconnu';
                $members = (int) $session->member_count;

                return "{$guild} - {$channel} ({$members})";
            })
            ->join("\n");
    }

    private static function currentVoiceChannelsActivity(DiscordBot $bot): ?string
    {
        $lastActivity = $bot->activeVoiceSessions
            ->pluck('last_activity_at')
            ->filter()
            ->sortDesc()
            ->first();

        return $lastActivity ? 'Activité '.$lastActivity->diffForHumans() : null;
    }

    private static function isStaleConnecting(DiscordBot $bot): bool
    {
        if (! $bot->is_active || $bot->connection_status !== 'connecting') {
            return false;
        }

        $reference = $bot->restart_requested_at ?? $bot->updated_at;

        return $reference !== null && $reference->lt(now()->subSeconds(90));
    }

    private static function botLog(DiscordBot $bot, string $level, string $event, string $message): void
    {
        SystemLog::query()->create([
            'level' => $level,
            'source' => 'discord-bot',
            'event' => $event,
            'message' => $bot->name.' : '.$message,
            'context' => [
                'bot_id' => $bot->id,
                'bot_name' => $bot->name,
            ],
            'occurred_at' => now(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDiscordBots::route('/'),
            'create' => Pages\CreateDiscordBot::route('/create'),
            'edit' => Pages\EditDiscordBot::route('/{record}/edit'),
        ];
    }
}
