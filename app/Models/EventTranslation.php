<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $event_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $short_description
 * @property string|null $description
 * @property string|null $seo_title
 * @property string|null $seo_description
 */
class EventTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'locale',
        'title',
        'short_description',
        'description',
        'seo_title',
        'seo_description',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

        protected static function boot(): void
        {
            parent::boot();
            static::creating(function (self $model) {
                if (empty($model->public_id)) {
                    $model->public_id = (string) \Illuminate\Support\Str::ulid()->toBase32();
                }
            });
        }

}
