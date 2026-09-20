<?php

declare(strict_types=1);

namespace Nabilet\Modules\Organizations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $logo
 * @property string|null $email
 * @property string|null $phone
 * @property string $status
 * @property array|null $settings_json
 * @property \DateTimeImmutable $created_at
 * @property \DateTimeImmutable $updated_at
 */
class Organization extends Model
{
    use SoftDeletes;

    protected $table = 'organizations';

    protected $fillable = [
        'public_id',
        'name',
        'slug',
        'description',
        'logo',
        'email',
        'phone',
        'status',
        'settings_json',
    ];

    protected $casts = [
        'settings_json' => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s.u',
        'updated_at' => 'datetime:Y-m-d H:i:s.u',
    ];

    /**
     * Get all venues for this organization.
     */
    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class, 'organization_id');
    }

    /**
     * Get all events for this organization.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Events\Models\Event::class, 'organization_id');
    }

    /**
     * Get all orders for this organization.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Orders\Models\Order::class, 'organization_id');
    }

    /**
     * Get all API keys for this organization.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'organization_id');
    }

    /**
     * Get all checkin devices for this organization.
     */
    public function checkinDevices(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Checkin\Models\CheckinDevice::class, 'organization_id');
    }
}
