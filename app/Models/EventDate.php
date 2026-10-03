<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventDate model - represents a specific date/time when an event occurs.
 * An event can have multiple dates (e.g., multi-day festival, recurring shows).
 */
class EventDate extends Model
{
    use SoftDeletes;

    protected $table = 'event_dates';

    protected $fillable = [
        'public_id',
        'event_id',
        'start_at',
        'end_at',
        'status',
        'name',
        'capacity',
        'is_sold_out',
        'sales_start_at',
        'sales_end_at',
        'notes',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'sales_start_at' => 'datetime',
        'sales_end_at' => 'datetime',
        'is_sold_out' => 'boolean',
        'capacity' => 'integer',
        'deleted_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    }

    /**
     * Get the event that owns this date.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    /**
     * Check if this date is currently available for booking.
     */
    public function isAvailable(): bool
    {
        if ($this->is_sold_out) {
            return false;
        }

        if ($this->status !== 'scheduled') {
            return false;
        }

        $now = now();

        if ($this->sales_start_at && $now->lt($this->sales_start_at)) {
            return false;
        }

        if ($this->sales_end_at && $now->gt($this->sales_end_at)) {
            return false;
        }

        return true;
    }

    /**
     * Get the duration in minutes.
     */
    public function getDurationMinutesAttribute(): ?int
    {
        if (!$this->end_at) {
            return null;
        }

        return (int) $this->start_at->diffInMinutes($this->end_at);
    }
}
