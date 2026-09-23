<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Event Schedule Item model - timetable/session items for events.
 */
class EventScheduleItem extends Model
{
    protected $table = 'event_schedule_items';

    protected $fillable = [
        'event_id',
        'event_date_id',
        'title',
        'description',
        'start_time',
        'duration_minutes',
        'location',
        'type',
        'speaker_ids',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'speaker_ids' => 'array',
        'duration_minutes' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    const TYPES = ['session', 'break', 'registration', 'keynote', 'workshop', 'panel', 'qna'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function eventDate(): BelongsTo
    {
        return $this->belongsTo(EventDate::class, 'event_date_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByType(Builder $query, ?string $type): Builder
    {
        if ($type) {
            return $query->where('type', $type);
        }
        return $query;
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('start_time')
            ->orderBy('sort_order');
    }

    public function getEndTimeAttribute(): string
    {
        $start = \Carbon\Carbon::parse($this->start_time);
        return $start->addMinutes($this->duration_minutes)->format('H:i');
    }

    public function getDurationFormattedAttribute(): string
    {
        $hours = intdiv($this->duration_minutes, 60);
        $minutes = $this->duration_minutes % 60;
        
        if ($hours > 0 && $minutes > 0) {
            return "{$hours}ч {$minutes}мин";
        } elseif ($hours > 0) {
            return "{$hours}ч";
        } else {
            return "{$minutes}мин";
        }
    }
}
