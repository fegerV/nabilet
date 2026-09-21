<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Organizations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Organization Model - Canonical Implementation
 * 
 * Represents a tenant/organization in the multi-tenant system.
 * 
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

    protected function casts(): array
    {
        return [
            'settings_json' => 'array',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    /**
     * Get the owner of this organization.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get all users belonging to this organization.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_organization')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * Get the venues belonging to this organization.
     */
    public function venues(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Venues\Models\Venue::class, 'organization_id');
    }

    /**
     * Get the events belonging to this organization.
     */
    public function events(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Events\Models\Event::class, 'organization_id');
    }

    /**
     * Get the orders belonging to this organization.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Orders\Models\Order::class, 'organization_id');
    }

    /**
     * Get the pages belonging to this organization.
     */
    public function pages(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Content\Models\Page::class, 'organization_id');
    }

    /**
     * Get the media assets belonging to this organization.
     */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Content\Models\MediaAsset::class, 'organization_id');
    }

    /**
     * Get the promo codes belonging to this organization.
     */
    public function promoCodes(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Orders\Models\PromoCode::class, 'organization_id');
    }

    /**
     * Get the ticket templates belonging to this organization.
     */
    public function ticketTemplates(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Tickets\Models\TicketTemplate::class, 'organization_id');
    }

    /**
     * Get the checkin devices belonging to this organization.
     */
    public function checkinDevices(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Tickets\Models\CheckinDevice::class, 'organization_id');
    }

    /**
     * Get the offline bundles belonging to this organization.
     */
    public function offlineBundles(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Tickets\Models\OfflineBundle::class, 'organization_id');
    }

    /**
     * Get the API keys belonging to this organization.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\System\Models\ApiKey::class, 'organization_id');
    }

    /**
     * Get the webhook endpoints belonging to this organization.
     */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\System\Models\Webhook::class, 'organization_id');
    }

    /**
     * Get the AB experiments belonging to this organization.
     */
    public function abExperiments(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Analytics\Models\AbExperiment::class, 'organization_id');
    }
}
