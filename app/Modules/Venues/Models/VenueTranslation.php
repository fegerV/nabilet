<?php

declare(strict_types=1);

namespace App\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VenueTranslation Model
 */
class VenueTranslation extends Model
{
    protected $table = 'venue_translations';

    public $timestamps = false;

    protected $fillable = [
        'venue_id',
        'locale',
        'name',
        'description',
        'address',
        'seo_title',
        'seo_description',
    ];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
