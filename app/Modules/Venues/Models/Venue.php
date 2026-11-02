<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;
use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Sessions\Models\Session;

/**
 * Venue Model
 */
class Venue extends Model
{
    use TenantScoped;

    use HasPublicId;

    protected $table = 'venues';

    public $timestamps = true;

    /**
     * Глобальный читающий скоуп (тридцать девятая модель после Order,
     * Payment, Ticket, Refund, TicketScan, CheckinDevice, OfflineBundle,
     * PaymentTransaction, OrderItem, SeatHold, CartItem, Cart,
     * PromoCodeRedemption, WebhookDelivery, кластеров аб-тестов, лояльности,
     * переводов, схемы зала, Session, PromoCode и Webhook). Таблица хранит
     * собственную `organization_id` (фаза 3 — NOT NULL); запись защищена
     * трейтом, TenantResolver ведёт сюда `halls` как потомков.
     *
     * Витринные пути (публичный каталог, страница площадки, lookup по slug
     * для SEO-лендингов, залы площадки) и eager-чтения `sessions.venue` на
     * гостевых потоках — bypass в `VenueController`/`CatalogService`/
     * `HallService`/`EventPageController`/`EventRepository`; админские CRUD
     * и destroy со счётчиком сеансов остаются под фильтром (живая изоляция).
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected $fillable = [
        'public_id',
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

    /**
     * Адресация в маршрутах — по `public_id`, как у залов, событий и заказов.
     *
     * По умолчанию Laravel берёт ключом `id`, а путь приводится к int: запрос
     * `GET /venues/1abc` молча разрешался в площадку 1 (PHP приводит строку к
     * числу при вызове не-строгого кода фреймворка). С `public_id` такого
     * приведения нет в принципе — мусор в пути даёт 404, а не чужую запись.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

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
        return $this->belongsTo(\Nabilet\Modules\Core\Organizations\Models\Organization::class);
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
