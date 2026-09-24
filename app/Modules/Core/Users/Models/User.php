<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Users\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Core\Models\Role;
use Nabilet\Modules\Core\Models\UserRole;
use Nabilet\Modules\Core\Models\UserSession;
use Nabilet\Modules\Core\Models\LoginLog;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Notifications\Models\Consent;
use Nabilet\Modules\Notifications\Models\Notification;
use Nabilet\Modules\Notifications\Models\PrivacyRequest;
use Nabilet\Modules\Content\Models\MediaAsset;
use Nabilet\Modules\Analytics\Models\HeatmapEvent;
use Nabilet\Modules\Analytics\Models\AnalyticsEvent;
use Nabilet\Modules\Analytics\Models\AbAssignment;

/**
 * User Model - Canonical Implementation
 *
 * Represents a user in the system with all relationships.
 *
 * WHY IT EXTENDS `Illuminate\Foundation\Auth\User`
 *   This was a plain `Model`, which meant it could not be the subject of a guard:
 *   `Illuminate\Contracts\Auth\Authenticatable` is what `Auth::user()`,
 *   `$request->user()` and every policy expect, and `EloquentUserProvider` refuses
 *   anything else. The auth provider in `config/auth.php` pointed at
 *   `App\Models\User` instead — the Filament panel's model — while every module in
 *   the tree uses *this* one. A guard that returned the Filament model would hand
 *   controllers a different class for the same row, so `instanceof` checks and
 *   `userSessions()` would be missing exactly where they are needed.
 *
 *   The base class brings `Authenticatable`, `Authorizable`, `CanResetPassword`
 *   and `MustVerifyEmail`. It declares no `$fillable`, no `$table` and no
 *   `$timestamps`, so nothing declared below is overridden, and it adds no
 *   attribute — `verify-models-schema.php` sees the same field set as before.
 */
class User extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    /**
     * `password` and `remember_token` never leave the process.
     *
     * The base class does not declare `$hidden` (Laravel removed it), so without
     * this a `toArray()` — in a log line, an exception context, or a resource that
     * forgets a field — would carry the password hash. `AuthResource` lists its
     * fields explicitly, so this is defence in depth rather than the only guard.
     */
    protected $hidden = ['password', 'remember_token'];

    protected $fillable = [
        'public_id',
        'email',
        'email_verified_at',
        'password',
        'first_name',
        'last_name',
        'phone',
        'phone_verified_at',
        'status',
        'locale',
        'timezone',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime:Y-m-d H:i:s.u',
            'phone_verified_at' => 'datetime:Y-m-d H:i:s.u',
            'last_login_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
            'deleted_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function organizations(): BelongsToMany
        {
            return $this->belongsToMany(Organization::class, 'user_organization')
                ->withPivot('role_id');
        }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function userSessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function loginLogs(): HasMany
    {
        return $this->hasMany(LoginLog::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'customer_id');
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function privacyRequests(): HasMany
    {
        return $this->hasMany(PrivacyRequest::class);
    }

    public function grantedUserRoles(): HasMany
    {
        return $this->hasMany(UserRole::class, 'granted_by');
    }

    public function uploadedMediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'uploaded_by');
    }

    public function heatmapEvents(): HasMany
    {
        return $this->hasMany(HeatmapEvent::class);
    }

    public function analyticsEvents(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    public function abAssignments(): HasMany
    {
        return $this->hasMany(AbAssignment::class);
    }
}
