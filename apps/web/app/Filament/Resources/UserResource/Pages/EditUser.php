<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Supprimer')
                ->visible(fn (): bool => UserResource::canRemoveUser($this->record)),
        ];
    }

    protected function beforeSave(): void
    {
        $newRole = $this->form->getState()['role'] ?? $this->record->role;
        $wasPrivileged = in_array($this->record->role, ['super_admin', 'administrator'], true);
        $willBePrivileged = in_array($newRole, ['super_admin', 'administrator'], true);

        if ($wasPrivileged && ! $willBePrivileged && $this->lastPrivilegedUser()) {
            Notification::make()
                ->title('Modification impossible')
                ->body('Il faut conserver au moins un super admin ou administrateur.')
                ->danger()
                ->send();

            $this->halt();
        }
    }

    private function lastPrivilegedUser(): bool
    {
        return User::query()
            ->whereIn('role', ['super_admin', 'administrator'])
            ->whereKeyNot($this->record->getKey())
            ->doesntExist();
    }
}
