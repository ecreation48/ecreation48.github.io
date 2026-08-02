<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

Artisan::command('user:promote-admin {email}', function (string $email): int {
    $user = User::query()->where('email', $email)->first();

    if (! $user) {
        $this->error("Aucun utilisateur trouvé pour {$email}.");

        return 1;
    }

    $user->forceFill([
        'role' => 'super_admin',
        'email_verified_at' => $user->email_verified_at ?? now(),
    ])->save();

    $this->info("{$user->email} est maintenant super admin.");

    return 0;
})->purpose('Promouvoir un utilisateur en super admin.');

Artisan::command('user:has-admin', function (): int {
    $hasAdmin = User::query()
        ->whereIn('role', ['super_admin', 'administrator'])
        ->exists();

    $this->line($hasAdmin ? 'yes' : 'no');

    return $hasAdmin ? 0 : 1;
})->purpose('Vérifier si un super admin ou administrateur existe.');
