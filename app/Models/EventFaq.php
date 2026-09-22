<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Event FAQ model - frequently asked questions for an event.
 */
class EventFaq extends Model
{
    protected $table = 'event_faqs';

    protected $fillable = [
        'event_id',
        'question',
        'answer',
        'category',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    const CATEGORIES = ['ticket', 'venue', 'payment', 'general', 'accessibility'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory(Builder $query, ?string $category): Builder
    {
        if ($category) {
            return $query->where('category', $category);
        }
        return $query;
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('question');
    }
}
