<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthentikSsoController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $this->ensureConfigured();

        if (Auth::check()) {
            return redirect('/admin');
        }

        $state = Str::random(48);
        $nonce = Str::random(48);
        $discovery = $this->discovery();

        $request->session()->put('authentik_sso_state', $state);
        $request->session()->put('authentik_sso_nonce', $nonce);

        return redirect()->away($discovery['authorization_endpoint'].'?'.http_build_query([
            'client_id' => config('services.authentik.client_id'),
            'redirect_uri' => route('auth.sso.callback'),
            'response_type' => 'code',
            'scope' => config('services.authentik.scopes'),
            'state' => $state,
            'nonce' => $nonce,
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->ensureConfigured();

        if ($request->string('state')->toString() !== $request->session()->pull('authentik_sso_state')) {
            throw ValidationException::withMessages(['sso' => 'Session SSO invalide.']);
        }

        if ($request->filled('error')) {
            throw ValidationException::withMessages(['sso' => $request->string('error_description')->toString() ?: $request->string('error')->toString()]);
        }

        $discovery = $this->discovery();
        $tokenRequest = Http::asForm()
            ->acceptJson()
            ->timeout(20);

        $tokenPayload = [
                'grant_type' => 'authorization_code',
                'client_id' => config('services.authentik.client_id'),
                'redirect_uri' => route('auth.sso.callback'),
                'code' => $request->string('code')->toString(),
        ];

        if (config('services.authentik.token_auth_method') === 'client_secret_basic') {
            $tokenRequest = $tokenRequest->withBasicAuth(config('services.authentik.client_id'), config('services.authentik.client_secret'));
        } else {
            $tokenPayload['client_secret'] = config('services.authentik.client_secret');
        }

        $token = $tokenRequest
            ->post($discovery['token_endpoint'], $tokenPayload)
            ->throw()
            ->json();

        $profile = Http::withToken($token['access_token'] ?? '')
            ->acceptJson()
            ->timeout(20)
            ->get($discovery['userinfo_endpoint'])
            ->throw()
            ->json();
        $profile = array_merge($this->idTokenPayload((string) ($token['id_token'] ?? '')), $profile);

        $email = strtolower((string) ($profile['email'] ?? ''));
        $subject = (string) ($profile['sub'] ?? '');

        if ($email === '' || $subject === '') {
            throw ValidationException::withMessages(['sso' => 'Authentik n’a pas renvoyé email ou identifiant utilisateur.']);
        }

        $user = User::query()->where('sso_provider', 'authentik')->where('sso_provider_id', $subject)->first()
            ?? User::query()->where('email', $email)->first();

        $user ??= new User(['email' => $email]);
        $user->forceFill([
            'name' => $profile['name'] ?? $profile['preferred_username'] ?? $email,
            'email' => $email,
            'password' => $user->exists ? $user->password : Str::password(48),
            'email_verified_at' => ($profile['email_verified'] ?? false) ? now() : $user->email_verified_at,
            'sso_provider' => 'authentik',
            'sso_provider_id' => $subject,
            'role' => $this->roleFromGroups($profile),
            'last_login_at' => now(),
        ])->save();

        $request->session()->forget('authentik_sso_nonce');
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect('/admin');
    }

    private function ensureConfigured(): void
    {
        if (! config('services.authentik.enabled')) {
            abort(404);
        }

        if (! config('services.authentik.client_id') || ! config('services.authentik.client_secret')) {
            throw ValidationException::withMessages(['sso' => 'La connexion SSO Authentik n’est pas configurée.']);
        }
    }

    private function discovery(): array
    {
        $issuer = rtrim((string) config('services.authentik.issuer_url'), '/');

        return cache()->remember('authentik_oidc_discovery:'.sha1($issuer), 3600, fn (): array => Http::acceptJson()
            ->timeout(15)
            ->get($issuer.'/.well-known/openid-configuration')
            ->throw()
            ->json());
    }

    private function roleFromGroups(array $profile): string
    {
        $groups = collect($profile['groups'] ?? $profile['ak_groups'] ?? [])
            ->map(fn (mixed $group): string => is_array($group) ? (string) ($group['name'] ?? '') : (string) $group)
            ->filter()
            ->values();

        if ($groups->contains(config('services.authentik.admin_group'))) {
            return 'super_admin';
        }

        if ($groups->contains(config('services.authentik.responsable_group'))) {
            return 'administrator';
        }

        if ($groups->contains(config('services.authentik.moderator_group'))) {
            return 'moderator';
        }

        return config('services.authentik.default_role');
    }

    private function idTokenPayload(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) < 2) {
            return [];
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')) ?: '', true);

        return is_array($payload) ? $payload : [];
    }
}
