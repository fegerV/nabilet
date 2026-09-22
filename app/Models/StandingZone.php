<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $sector_id
 * @property string $name
 * @property int $capacity
 * @property int $price_amount
 * @property string $currency
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class StandingZone extends Model
{
    protected $fillable = [
        'public_id',
        'sector_id',
        'name',
        'capacity',
        'price_amount',
        'currency',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'price_amount' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'sector_id');
    }
}
