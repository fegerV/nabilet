<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\HallSchemas\Domain\SchemaVersionState;
use Nabilet\Modules\Sessions\Models\Session;

/**
 * Hall Model
 */
class Hall extends Model
{
    use TenantScoped;
    use HasPublicId;

    protected $table = 'halls';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'venue_id',
        'name',
        'description',
        'city',
        'address',
        'exterior_photo_url',
        'interior_photo_url',
        'capacity',
        'width',
        'height',
        'status',
    ];

    /**
     * Глобальный читающий скоуп (двадцать девятая модель после Order,
     * Payment, Ticket, Refund, TicketScan, CheckinDevice, OfflineBundle,
     * PaymentTransaction, OrderItem, SeatHold, CartItem, Cart,
     * PromoCodeRedemption, WebhookDelivery, AbAssignment, AbVariant, AbMetric,
     * InventoryItem, Raffle- и Translation-кластеров). `halls` хранит
     * собственную `organization_id` (фаза 3 — NOT NULL), запись защищена
     * трейтом, TenantResolver-карта ведёт через `venue_id` → `venues`.
     *
     * Публичные пути каталога залов (`GET /halls`, `/venues/{id}/halls`,
     * схема для витрины мест) контекста не имеют — их чтения обходят скоуп
     * в bypass в `HallService`/`HallController`; админские CRUD и управление
     * версиями схемы остаются под фильтром.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function schemaVersions(): HasMany
    {
        return $this->hasMany(HallSchemaVersion::class);
    }

    /**
     * Опубликованная версия схемы зала — та, по которой продаются места.
     *
     * Отношения не было, хотя `HallRepository` его жадно грузил:
     * `with(['currentSchemaVersion'])` в `findByVenue()` и
     * `with(['currentSchemaVersion.sectors.rows.seats'])` в `find()`. Laravel
     * падает на первом же неизвестном отношении, поэтому
     * `GET /venues/{publicId}/halls` отвечал 500 «Call to undefined relationship
     * [currentSchemaVersion] on model [Hall]» — список залов площадки не
     * открывался вообще, а вместе с ним и редактор зала из админки.
     *
     * Опубликованной версия может быть ровно одна: `HallRepository::publishSchemaVersion()`
     * переводит предыдущую в `archived`. Отсюда `hasOne` + сортировка по
     * `version` — на случай, если в базе остался исторический беспорядок.
     */
    public function currentSchemaVersion(): HasOne
    {
        // Статус — из `SchemaVersionState`, а не литералом: словарь
        // draft/published/archived задан `ck_schema_status`, и в проекте уже была
        // вторая копия этого словаря (`Venues\Halls\Domain\SchemaVersionPolicy`
        // со status 'active', которого в БД нет вовсе) — удалена как мёртвая.
        // Литерал здесь разошёлся бы с ней молча: отношение просто перестало бы
        // находить версию.
        return $this->hasOne(HallSchemaVersion::class)
            ->where('status', SchemaVersionState::PUBLISHED)
            ->orderByDesc('version');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }
}
