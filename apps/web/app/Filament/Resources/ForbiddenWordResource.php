<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ForbiddenWordResource\Pages;
use App\Models\ForbiddenWord;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ForbiddenWordResource extends Resource
{
    protected static ?string $model = ForbiddenWord::class;
    protected static ?string $modelLabel = 'mot interdit';
    protected static ?string $pluralModelLabel = 'mots interdits';
    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';
    protected static ?string $navigationLabel = 'Mots interdits';
    protected static ?string $navigationGroup = 'Modération';
    protected static ?int $navigationSort = 35;

    public static function canViewAny(): bool
    {
        return auth()->user()?->canApplySanctions() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canApplySanctions() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->canApplySanctions() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Mot interdit')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('word')
                        ->label('Mot ou expression')
                        ->required()
                        ->maxLength(120)
                        ->columnSpanFull(),
                    Forms\Components\Select::make('severity')
                        ->label('Gravité')
                        ->required()
                        ->options(self::severityOptions())
                        ->default('medium'),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Actif')
                        ->default(true),
                    Forms\Components\Textarea::make('notes')
                        ->label('Notes')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->latest())
            ->columns([
                Tables\Columns\TextColumn::make('word')
                    ->label('Mot')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('severity')
                    ->label('Gravité')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::severityOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modifié le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('severity')
                    ->label('Gravité')
                    ->options(self::severityOptions()),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Actif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Modifier'),
                Tables\Actions\DeleteAction::make()->label('Supprimer'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()->label('Supprimer la sélection'),
            ]);
    }

    public static function severityOptions(): array
    {
        return [
            'low' => 'Faible',
            'medium' => 'Moyenne',
            'high' => 'Élevée',
            'critical' => 'Critique',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListForbiddenWords::route('/'),
            'create' => Pages\CreateForbiddenWord::route('/create'),
            'edit' => Pages\EditForbiddenWord::route('/{record}/edit'),
        ];
    }
}
