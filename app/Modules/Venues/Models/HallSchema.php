<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HallSchema Model
 * 
 * Represents a hall seating schema configuration.
 * Used by the Vue.js Hall Editor to store and retrieve seating layouts.
 */
class HallSchema extends Model
{
    protected $table = 'hall_schemas';

    protected $fillable = [
        'venue_id',
        'name',
        'schema',
        'is_active',
    ];

    protected $casts = [
        'schema' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get the venue that owns this schema
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
