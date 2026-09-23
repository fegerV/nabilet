<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Event Sponsor model - represents a sponsor of an event.
 */
class EventSponsor extends Model
{
    protected $table = 'event_sponsors';

    protected $fillable = [
        'event_id',
        'name',
        'logo',
        'tier',
        'website',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    const TIERS = ['bronze', 'silver', 'gold', 'platinum', 'standard'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByTier(Builder $query, string $tier): Builder
    {
        return $query->where('tier', $tier);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('tier')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function getTierBadgeColorAttribute(): string
    {
        return match($this->tier) {
            'platinum' => 'purple',
            'gold' => 'yellow',
            'silver' => 'gray',
            'bronze' => 'orange',
            default => 'blue',
        };
    }
}
