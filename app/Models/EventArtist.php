<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Event Artist model - performing artists at concerts/festivals.
 */
class EventArtist extends Model
{
    protected $table = 'event_artists';

    protected $fillable = [
        'event_id',
        'name',
        'genre',
        'bio',
        'photo',
        'social_links',
        'stage',
        'performance_at',
        'sort_order',
        'is_headliner',
    ];

    protected $casts = [
        'social_links' => 'array',
        'performance_at' => 'datetime',
        'sort_order' => 'integer',
        'is_headliner' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function scopeHeadliners(Builder $query): Builder
    {
        return $query->where('is_headliner', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_headliner')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function scopeByStage(Builder $query, ?string $stage): Builder
    {
        if ($stage) {
            return $query->where('stage', $stage);
        }
        return $query;
    }
}
