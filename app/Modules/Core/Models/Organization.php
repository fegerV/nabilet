<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Organization Model
 * 
 * Represents a tenant/organization in the multi-tenant system.
 */
class Organization extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'organizations';

    /**
     * The attributes that are mass assignable.
     */
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

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'settings_json' => 'array',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    /**
     * Get the users belonging to this organization.
     */
    public function users(): BelongsToMany
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
        return $this->hasMany(Venue::class);
    }

    /**
     * Get the events belonging to this organization.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Get the orders belonging to this organization.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the pages belonging to this organization.
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /**
     * Get the media assets belonging to this organization.
     */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /**
     * Get the promo codes belonging to this organization.
     */
    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }

    /**
     * Get the ticket templates belonging to this organization.
     */
    public function ticketTemplates(): HasMany
    {
        return $this->hasMany(TicketTemplate::class);
    }

    /**
     * Get the checkin devices belonging to this organization.
     */
    public function checkinDevices(): HasMany
    {
        return $this->hasMany(CheckinDevice::class);
    }

    /**
     * Get the offline bundles belonging to this organization.
     */
    public function offlineBundles(): HasMany
    {
        return $this->hasMany(OfflineBundle::class);
    }

    /**
     * Get the API keys belonging to this organization.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * Get the audit logs belonging to this organization.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Get the embed domains belonging to this organization.
     */
    public function embedDomains(): HasMany
    {
        return $this->hasMany(EmbedDomain::class);
    }

    /**
     * Get the webhook endpoints belonging to this organization.
     */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    /**
     * Get the AB experiments belonging to this organization.
     */
    public function abExperiments(): HasMany
    {
        return $this->hasMany(AbExperiment::class);
    }
}
