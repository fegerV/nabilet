<?php

declare(strict_types=1);

namespace App\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Venue Model
 */
class Venue extends Model
{
    protected $table = 'venues';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'organization_id',
        'name',
        'slug',
        'description',
        'country',
        'region',
        'city',
        'address',
        'latitude',
        'longitude',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Core\Models\Organization::class);
    }

    public function halls(): HasMany
    {
        return $this->hasMany(Hall::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    public function venueTranslations(): HasMany
    {
        return $this->hasMany(VenueTranslation::class);
    }
}
