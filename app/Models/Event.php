<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Event model - represents an event that can have multiple dates/sessions.
 */
class Event extends Model
{
    use SoftDeletes;

    protected $table = 'events';

    protected $fillable = [
        'public_id',
        'organization_id',
        'category_id',
        'title',
        'slug',
        'short_description',
        'description',
        'poster',
        'cover',
        'age_limit',
        'duration_minutes',
        'status',
        'published_at',
        'seo_title',
        'seo_description',
        'canonical_url',
        'robots',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'duration_minutes' => 'integer',
        'deleted_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
            if ($model->organization_id === null) {
                $model->organization_id = auth()->user()?->organization_id ?? 1;
            }
        });
    }

    /**
     * Get the category that owns this event.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    /**
     * Get the organization that owns this event.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * Get all dates/sessions for this event.
     */
    public function dates(): HasMany
    {
        return $this->hasMany(EventDate::class, 'event_id');
    }

    /**
     * Get only upcoming dates for this event.
     */
    public function upcomingDates(): HasMany
    {
        return $this->dates()
            ->where('start_at', '>=', now())
            ->where('status', 'scheduled')
            ->orderBy('start_at', 'asc');
    }

    /**
     * Get the earliest upcoming date for this event.
     */
    public function getNextDateAttribute(): ?EventDate
    {
        return $this->upcomingDates()->first();
    }

    /**
     * Check if event has any available dates.
     */
    public function hasAvailableDates(): bool
    {
        return $this->dates()
            ->where('is_sold_out', false)
            ->where('status', 'scheduled')
            ->exists();
    }
}