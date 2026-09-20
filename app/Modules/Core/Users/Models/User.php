<?php

declare(strict_types=1);

namespace App\Modules\Core\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\Core\Organizations\Models\Organization;

/**
 * User Model - Users Module
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

    public function orders(): HasMany
    {
        return $this->hasMany(\App\Modules\Core\Orders\Models\Order::class, 'customer_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(\App\Modules\Core\Tickets\Models\Ticket::class, 'customer_id');
    }
}
