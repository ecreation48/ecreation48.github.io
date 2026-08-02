<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $modelLabel = 'utilisateur';
    protected static ?string $pluralModelLabel = 'utilisateurs';
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Utilisateurs';
    protected static ?string $navigationGroup = 'Configuration';
    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return in_array(Auth::user()?->role, ['super_admin', 'administrator'], true);
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return self::canViewAny() && self::canRemoveUser($record);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Compte')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nom')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\Select::make('role')
                        ->label('Rôle')
                        ->required()
                        ->options(self::roleOptions())
                        ->default('viewer')
                        ->helperText('Le rôle détermine les accès disponibles dans l’administration.'),
                    Forms\Components\Toggle::make('email_verified_at')
                        ->label('Compte vérifié')
                        ->formatStateUsing(fn ($state): bool => filled($state))
                        ->dehydrateStateUsing(fn (bool $state) => $state ? now() : null),
                    Forms\Components\TextInput::make('password')
                        ->label('Mot de passe')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->minLength(10)
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->helperText('Laisse vide en édition pour conserver le mot de passe actuel.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->latest())
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('Rôle')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::roleOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'administrator' => 'warning',
                        'moderator' => 'success',
                        'reviewer' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Vérifié')
                    ->boolean()
                    ->state(fn (User $record): bool => filled($record->email_verified_at)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('Rôle')
                    ->options(self::roleOptions()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Modifier'),
                Tables\Actions\DeleteAction::make()
                    ->label('Supprimer')
                    ->visible(fn (User $record): bool => self::canRemoveUser($record)),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->label('Supprimer la sélection')
                    ->deselectRecordsAfterCompletion()
                    ->using(fn ($records) => $records
                        ->reject(fn (User $record): bool => ! self::canRemoveUser($record))
                        ->each
                        ->delete()),
            ]);
    }

    public static function roleOptions(): array
    {
        return [
            'super_admin' => 'Super admin',
            'administrator' => 'Administrateur',
            'moderator' => 'Modérateur',
            'reviewer' => 'Relecteur',
            'viewer' => 'Observateur',
        ];
    }

    public static function canRemoveUser(User $user): bool
    {
        if (Auth::id() === $user->getKey()) {
            return false;
        }

        if (! in_array($user->role, ['super_admin', 'administrator'], true)) {
            return true;
        }

        return User::query()
            ->whereIn('role', ['super_admin', 'administrator'])
            ->whereKeyNot($user->getKey())
            ->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
