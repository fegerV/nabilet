<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EventTranslation Model
 */
class EventTranslation extends Model
{
    use TenantScoped;
    protected $table = 'event_translations';

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
}
