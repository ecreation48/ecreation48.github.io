<?php

namespace App\Filament\Resources\ForbiddenWordResource\Pages;

use App\Filament\Resources\ForbiddenWordResource;
use App\Models\ForbiddenWord;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditForbiddenWord extends EditRecord
{
    protected static string $resource = ForbiddenWordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Supprimer'),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $normalized = ForbiddenWord::normalize((string) ($data['word'] ?? ''));
        $exists = ForbiddenWord::query()
            ->where('normalized_word', $normalized)
            ->whereKeyNot($record->getKey())
            ->exists();

        if ($exists) {
            Notification::make()
                ->title('Le mot existe déjà')
                ->body('La modification créerait un doublon dans la base de mots interdits.')
                ->warning()
                ->send();

            throw new Halt();
        }

        return parent::handleRecordUpdate($record, $data);
    }
}
