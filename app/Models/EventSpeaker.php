<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Event Speaker model - represents a speaker at an event.
 */
class EventSpeaker extends Model
{
    protected $table = 'event_speakers';

    protected $fillable = [
        'event_id',
        'name',
        'position',
        'bio',
        'avatar',
        'social_links',
        'sort_order',
        'is_featured',
    ];

    protected $casts = [
        'social_links' => 'array',
        'sort_order' => 'integer',
        'is_featured' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
