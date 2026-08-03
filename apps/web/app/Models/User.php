<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory;
    use HasUuid;
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'sso_provider', 'sso_provider_id', 'last_login_at'];
    protected $hidden = ['password', 'remember_token'];

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $hasPrivilegedUser = User::query()
                ->whereIn('role', ['super_admin', 'administrator'])
                ->exists();

            if (! $hasPrivilegedUser) {
                $user->role = 'super_admin';
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, ['super_admin', 'administrator', 'moderator', 'reviewer', 'viewer'], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function canManageReports(): bool
    {
        return in_array($this->role, ['super_admin', 'administrator', 'moderator'], true);
    }

    public function canApplySanctions(): bool
    {
        return in_array($this->role, ['super_admin', 'administrator'], true);
    }

    public function canManageChannels(): bool
    {
        return in_array($this->role, ['super_admin', 'administrator'], true);
    }

    public function canManageConfiguration(): bool
    {
        return $this->role === 'super_admin';
    }
}
