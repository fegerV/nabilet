<?php

declare(strict_types=1);

namespace App\Modules\Core\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\Core\Organizations\Models\Organization;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Models\UserRole;
use App\Modules\Core\Models\UserSession;
use App\Modules\Core\Models\LoginLog;
use App\Modules\Orders\Models\Order;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Carts\Models\Cart;
use App\Modules\Notifications\Models\Consent;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\PrivacyRequest;
use App\Modules\Content\Models\MediaAsset;
use App\Modules\Analytics\Models\HeatmapEvent;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AbAssignment;

/**
 * User Model - Canonical Implementation
 * 
 * Represents a user in the system with all relationships.
 */
class User extends Model
{
    protected $table = 'users';

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
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role')
            ->withTimestamps();
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
