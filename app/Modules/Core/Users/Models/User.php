<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Users\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Nabilet\Modules\Core\Users\Domain\UserStatus;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Core\Models\Role;
use Nabilet\Modules\Core\Models\UserRole;
use Nabilet\Modules\Core\Models\UserSession;
use Nabilet\Modules\Core\Models\LoginLog;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Cart\Models\Cart;
use App\Models\Consent;
use App\Models\Notification;
use App\Models\PrivacyRequest;
use Nabilet\Modules\Media\Models\MediaAsset;
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
 *   `App\Models\User` instead — the web guard's model — while every module in
 *   the tree uses *this* one. A guard that returned the other model would hand
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
     * `public_id` is the identifier that leaves the process.
     *
     * The column is `CHAR(26)` in `nabilet_core_spec` — that is the width of a
     * Crockford Base32 ULID, not of a UUID. A UUID is 36 characters, so writing
     * one is not a cosmetic mismatch: MySQL rejects the INSERT with 1406 ("Data
     * too long for column 'public_id'"), which is how registration failed. The
     * generation lives here rather than in the repository so that every creation
     * path — repository, factory, seeder, `create()` on a relation — gets a
     * value the column can actually hold.
     *
     * The `null` guard means a caller that supplies its own `public_id` (a data
     * import, a deterministic fixture) keeps it.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = (string) Str::ulid()->toBase32();
            }
        });
    }

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

    /**
     * Display name, as one string.
     *
     * MOVED HERE from `App\Models\User` — that class was a *second* model over
     * the same `users` table with its own `$fillable` and none of the relations
     * declared below. The duplication was not harmless: `Order::user()` resolves
     * to THIS model, so `$order->user?->name` was always null and
     * `OrderNotificationData::customerName()` silently fell back to
     * «Покупатель». An accessor that only one of two models for one table has is
     * a bug generator, so it lives with the model now.
     */
    public function getNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')) ?: 'User';
    }

    /**
     * Access to the admin panel: an account that is not `active` cannot enter.
     *
     * ROLE CHECKS ARE NOT HERE. `isAdminSession()` / the `admin` middleware
     * decide what a session may do; this answers only whether the account
     * behind it still exists for that purpose (`banned` = no). Kept from
     * `App\Models\User`, which was the only place it lived.
     */
    public function canAccessAdmin(): bool
    {
        return $this->status === UserStatus::ACTIVE;
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

    /**
     * Role membership by slug — one query, no hydration of the whole relation.
     *
     * MOVED HERE from `App\Models\User` (see `getNameAttribute()`): a role check
     * that only one of two models for one table offers is a check that silently
     * returns false on the other.
     */
    public function hasRole(string $role): bool
    {
        return $this->roles()->where('slug', $role)->exists();
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
