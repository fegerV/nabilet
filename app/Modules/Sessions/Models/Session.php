<?php

declare(strict_types=1);

namespace Nabilet\Modules\Sessions\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Nabilet\Modules\Sessions\Domain\SalesWindow;

/**
 * Session Model (Event Session)
 */
class Session extends Model
{
    use TenantScoped;
    use HasPublicId;

    protected $table = 'sessions';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'event_id',
        'venue_id',
        'hall_id',
        'schema_version_id',
        'starts_at',
        'ends_at',
        'sales_start_at',
        'sales_end_at',
        'timezone',
        'status',
    ];

    /**
     * Глобальный читающий скоуп (тридцать шестая модель после Order,
     * Payment, Ticket, Refund, TicketScan, CheckinDevice, OfflineBundle,
     * PaymentTransaction, OrderItem, SeatHold, CartItem, Cart,
     * PromoCodeRedemption, WebhookDelivery, AbAssignment, AbVariant, AbMetric,
     * InventoryItem, Raffle-, Translation-кластеров и кластера схемы зала).
     * `sessions` хранит собственную `organization_id` (фаза 3 — NOT NULL);
     * запись защищена трейтом, TenantResolver-карта ведёт также через
     * `event_id` → `events`.
     *
     * Публичные пути (каталог сеансов и карточка `GET /sessions/*`,
     * расписание в embed-виджете) контекста не имеют — их чтения обходят
     * скоуп в bypass в `SessionController`/`EmbedController`; админские CRUD
     * и системные пути чекера остаются под фильтром (живая изоляция).
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime:Y-m-d H:i:s.u',
            'ends_at' => 'datetime:Y-m-d H:i:s.u',
            'sales_start_at' => 'datetime:Y-m-d H:i:s.u',
            'sales_end_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    /**
     * Нормализация дат при записи — см. `normalizeDatetime()`.
     *
     * ПОЧЕМУ МУТАТОРЫ, А НЕ КАСТ. Каст `datetime:<формат>` Laravel
     * классифицирует как `custom_datetime`, а `setAttribute` намеренно
     * исключает `custom_datetime` из `isDateCastable()`: значение при записи
     * остаётся СЫРЫМ. Валидация `date` при этом принимает ISO-8601 с таймзоной
     * (`2026-12-20T19:00:00+05:00`, `…Z`) — такая строка уходила в INSERT
     * дословно, и MySQL strict отвечал 1292 → HTTP 500 на `POST /sessions`.
     * Форма админки не спотыкалась только потому, что шлёт дату без времени
     * (`<input type="date">`), которую MySQL принимает как полуночь.
     */
    protected function startsAt(): Attribute
    {
        return Attribute::set(fn (mixed $value) => $this->normalizeDatetime($value));
    }

    protected function endsAt(): Attribute
    {
        return Attribute::set(fn (mixed $value) => $this->normalizeDatetime($value));
    }

    protected function salesStartAt(): Attribute
    {
        return Attribute::set(fn (mixed $value) => $this->normalizeDatetime($value));
    }

    protected function salesEndAt(): Attribute
    {
        return Attribute::set(fn (mixed $value) => $this->normalizeDatetime($value));
    }

    /**
     * Любую валидную дату/время — в сторовый формат колонки `DATETIME(6)`.
     *
     * Семантика — «настенное время», как у родного `fromDateTime()`: Carbon
     * парсит строку в её собственном смещении (`19:00+05:00` остаётся 19:00,
     * `15:00Z` остаётся 15:00), смещение отбрасывается. Перевод в другой
     * пояс здесь не делается: у сеанса есть колонка `timezone` для отображения,
     * а конвертация на записи подменила бы время, которое пользователь ввёл
     * как местное.
     */
    private function normalizeDatetime(mixed $value): mixed
    {
        if ($value === null || $value === '' || $value instanceof \DateTimeInterface) {
            return $value;
        }

        return Carbon::parse((string) $value)->format('Y-m-d H:i:s.u');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Events\Models\Event::class);
    }

    /**
     * Найти сессию по числовому id или public_id.
     * Покупательский API отдаёт числовой id сессии; CartService хранит
     * public_id в cart.session_id. Метод покрывает оба формата.
     */
    public static function findOrFailBySessionId(string|int $sessionId): self
    {
        $query = static::query()->where(function ($q) use ($sessionId) {
            $q->where('id', $sessionId)->orWhere('public_id', $sessionId);
        });

        return $query->firstOrFail();
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Venues\Models\Venue::class);
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Venues\Models\Hall::class);
    }

    /**
     * Sales gate: the operational switch (`status = on_sale`) AND the time window
     * (`sales_start_at` / `sales_end_at`) must both permit selling right now.
     *
     * The two gates are independent by design (ТЗ §13): the window is scheduled,
     * `on_sale` is the organizer's emergency stop. A session in any other status
     * (draft, scheduled, sold_out, closed, completed, cancelled) never sells —
     * see SessionStateMachine::sellable().
     *
     * РАННИЙ ДОСТУП КЛУБА (фаза 3b). `$earlyAccessHours > 0` сдвигает НАЧАЛО
     * окна назад на указанное число часов — «клубная» очередь продажи впереди
     * общей. Насколько конкретный покупатель «клубный», здесь не решается:
     * число часов приносит вызывающий (`LoyaltySettingsService::
     * earlyAccessHoursForUser()`), а само правило живёт в фреймворк-фри
     * `Domain\SalesWindow` — его можно проверить без базы (`tests/run.php`).
     *
     * Оперативный переключатель при этом НЕ ослабляется намеренно: ранний доступ
     * открывает окно раньше, но не продаёт сеанс, который организатор снял с
     * продажи, и не оживляет завершённые/отменённые.
     */
    public function isSellableAt(?\DateTimeInterface $now = null, int $earlyAccessHours = 0): bool
    {
        return $this->salesWindow()->isSellableAt($now, $earlyAccessHours);
    }

    /** Факты окна продаж для фреймворк-фри решения `Domain\SalesWindow`. */
    private function salesWindow(): SalesWindow
    {
        return new SalesWindow(
            (string) $this->status,
            $this->sales_start_at,
            $this->sales_end_at,
        );
    }

    /**
     * Human-readable reason why the session is not sellable, for error messages.
     *
     * `$earlyAccessHours` — тот же сдвиг окна, что у `isSellableAt()`: для
     * клубного покупателя текст называет не публичный старт, а когда откроется
     * «клубная» очередь, иначе отказ читается как «продаж не будет» хоть они и
     * открылись для него на сутки раньше общей.
     */
    public function salesBlockReason(?\DateTimeInterface $now = null, int $earlyAccessHours = 0): string
    {
        return $this->salesWindow()->blockReason($now, $earlyAccessHours);
    }

    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Venues\Models\HallSchemaVersion::class, 'schema_version_id');
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Inventory\Models\InventoryItem::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Cart\Models\Cart::class);
    }

    public function seatHolds(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Orders\Models\SeatHold::class);
    }

    public function ticketScans(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Tickets\Models\TicketScan::class);
    }
}
