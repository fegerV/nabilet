<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $table = 'users';

    protected $fillable = ['email', 'email_verified_at', 'password', 'first_name', 'last_name', 'phone', 'phone_verified_at', 'status', 'locale', 'timezone', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    }

    public function getNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')) ?: 'User';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'active';
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('slug', $role)->exists();
    }
}
