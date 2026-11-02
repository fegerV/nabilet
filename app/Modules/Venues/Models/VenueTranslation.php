<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VenueTranslation Model
 */
class VenueTranslation extends Model
{
    use TenantScoped;
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

    /**
     * Глобальный читающий скоуп (двадцать седьмая модель — см. докблок
     * `EventTranslation`). Запись защищена трейтом (TenantResolver-карта
     * ведёт через `venue_id` → `venues`). Читатель один — публичная страница
     * площадки `VenueController::show` (без auth), eager-загрузка через связь
     * `venueTranslations()` в bypass; у гостя контекста нет, адрес площадки
     * решает lookup самого места.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
