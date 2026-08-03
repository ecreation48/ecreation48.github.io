<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DiscordGuildResource\Pages;
use App\Models\DiscordChannel;
use App\Models\DiscordGuild;
use App\Models\DiscordMember;
use App\Models\DiscordRole;
use App\Services\DiscordGuildSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Throwable;

class DiscordGuildResource extends Resource
{
    protected static ?string $model = DiscordGuild::class;
    protected static ?string $navigationIcon = 'heroicon-o-server-stack';
    protected static ?string $navigationLabel = 'Serveurs Discord';
    protected static ?string $navigationGroup = 'Discord';

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
            Forms\Components\Section::make('Serveur')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('discord_id')
                        ->label('Discord ID')
                        ->required()
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('owner_discord_id')
                        ->label('Owner')
                        ->required()
                        ->maxLength(255)
                        ->datalist(fn (): array => DiscordMember::query()
                            ->orderBy('display_name')
                            ->limit(100)
                            ->get()
                            ->map(fn (DiscordMember $member) => "{$member->display_name} ({$member->discord_id})")
                            ->all())
                        ->helperText('Collez l’ID Discord du propriétaire. Après synchronisation, les noms connus apparaîtront en suggestion.'),
                    Forms\Components\Select::make('discord_bot_id')
                        ->label('Bot principal')
                        ->relationship('bot', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Toggle::make('is_active')
                        ->default(true),
                    Forms\Components\TextInput::make('icon'),
                ]),

            Forms\Components\Section::make('Modération')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('moderator_role_discord_id')
                        ->label('Rôle modérateur')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::roleOptions($search))
                        ->getOptionLabelUsing(fn (?string $value): ?string => self::roleLabel($value)),

                    Forms\Components\Select::make('log_channel_discord_id')
                        ->label('Salon de logs')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::channelOptions($search))
                        ->getOptionLabelUsing(fn (?string $value): ?string => self::channelLabel($value)),

                    Forms\Components\Select::make('report_channel_discord_id')
                        ->label('Salon de signalements')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::channelOptions($search))
                        ->getOptionLabelUsing(fn (?string $value): ?string => self::channelLabel($value)),

                    Forms\Components\Select::make('report_notification_channel_discord_id')
                        ->label('Salon de notifications de signalement')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::textChannelOptions($search))
                        ->getOptionLabelUsing(fn (?string $value): ?string => self::channelLabel($value))
                        ->helperText('Salon texte dans lequel le bot préviendra les rôles configurés quand un signalement vocal est créé.'),

                    Forms\Components\Select::make('report_mention_role_discord_ids')
                        ->label('Rôles à mentionner lors d’un signalement')
                        ->multiple()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::roleOptions($search))
                        ->getOptionLabelsUsing(fn (array $values): array => DiscordRole::query()
                            ->whereIn('discord_id', $values)
                            ->get()
                            ->mapWithKeys(fn (DiscordRole $role) => [$role->discord_id => "{$role->name} ({$role->discord_id})"])
                            ->all())
                        ->columnSpanFull()
                        ->helperText('Ces rôles seront mentionnés dans le salon de notifications configuré.'),

                    Forms\Components\KeyValue::make('moderation_config')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('discord_id')->copyable()->searchable(),
                Tables\Columns\TextColumn::make('bot.name')->label('Bot'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('channels_count')->counts('channels')->label('Salons'),
            ])
            ->actions([
                Tables\Actions\Action::make('syncDiscord')
                    ->label('Sync')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (DiscordGuild $record): void {
                        try {
                            app(DiscordGuildSyncService::class)->sync($record);
                            Notification::make()->title('Serveur synchronisé')->success()->send();
                        } catch (Throwable $error) {
                            Notification::make()->title('Synchronisation impossible')->body($error->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDiscordGuilds::route('/'),
            'create' => Pages\CreateDiscordGuild::route('/create'),
            'edit' => Pages\EditDiscordGuild::route('/{record}/edit'),
        ];
    }

    private static function roleOptions(string $search): array
    {
        return DiscordRole::query()
            ->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('discord_id', 'like', "%{$search}%"))
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (DiscordRole $role) => [$role->discord_id => "{$role->name} ({$role->discord_id})"])
            ->all();
    }

    private static function roleLabel(?string $value): ?string
    {
        return blank($value) ? null : DiscordRole::query()->where('discord_id', $value)->value('name') ?? $value;
    }

    private static function channelOptions(string $search): array
    {
        return DiscordChannel::query()
            ->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('discord_id', 'like', "%{$search}%"))
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (DiscordChannel $channel) => [$channel->discord_id => "{$channel->name} ({$channel->discord_id})"])
            ->all();
    }

    private static function textChannelOptions(string $search): array
    {
        return DiscordChannel::query()
            ->where('type', 'text')
            ->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('discord_id', 'like', "%{$search}%"))
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (DiscordChannel $channel) => [$channel->discord_id => "{$channel->name} ({$channel->discord_id})"])
            ->all();
    }

    private static function channelLabel(?string $value): ?string
    {
        return blank($value) ? null : DiscordChannel::query()->where('discord_id', $value)->value('name') ?? $value;
    }
}
