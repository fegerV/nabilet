<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $venue_id
 * @property string $locale
 * @property string|null $name
 * @property string|null $description
 * @property string|null $address
 * @property string|null $seo_title
 * @property string|null $seo_description
 */
class VenueTranslation extends Model
{
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
