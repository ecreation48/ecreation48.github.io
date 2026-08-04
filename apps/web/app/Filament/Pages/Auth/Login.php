<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Auth;

class Login extends BaseLogin
{
    public function mount(): void
    {
        if (Auth::check()) {
            $this->redirect('/admin', navigate: false);

            return;
        }

        if (config('services.authentik.enabled') && ! request()->boolean('manual')) {
            $this->redirectRoute('auth.sso.redirect', navigate: false);

            return;
        }

        parent::mount();
    }
}
