<?php

namespace App\Filament\Resources\ForbiddenWordResource\Pages;

use App\Filament\Resources\ForbiddenWordResource;
use App\Models\ForbiddenWord;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateForbiddenWord extends CreateRecord
{
    protected static string $resource = ForbiddenWordResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $normalized = ForbiddenWord::normalize((string) ($data['word'] ?? ''));

        if (ForbiddenWord::query()->where('normalized_word', $normalized)->exists()) {
            Notification::make()
                ->title('Le mot existe déjà')
                ->body('Aucun doublon n’a été ajouté à la base de mots interdits.')
                ->warning()
                ->send();

            throw new Halt();
        }

        return parent::handleRecordCreation($data);
    }
}
