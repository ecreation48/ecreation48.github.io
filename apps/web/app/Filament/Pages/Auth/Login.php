<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;

class Login extends BaseLogin
{
    public function mount(): void
    {
        if (config('services.authentik.enabled') && ! request()->boolean('manual')) {
            $this->redirectRoute('auth.sso.redirect', navigate: false);

            return;
        }

        parent::mount();
    }
}
