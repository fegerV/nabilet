<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Core\Support\HoldGrace;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Cart\Models\Cart;

/**
 * SeatHold Model - Database Persistence Layer
 * 
 * @property int $id
 * @property string $public_id
 * @property int $inventory_item_id
 * @property int $cart_id
 * @property int $session_id
 * @property int $quantity
 * @property string $expires_at
 * @property string|null $released_at
 * @property string|null $converted_at
 * @property \Carbon\CarbonImmutable $created_at
 */
class SeatHold extends Model
{
    /**
     * `seat_holds` has `created_at` but no `updated_at` (see
     * nabilet_core_spec/migrations.sql). Without this, Eloquent adds
     * `seat_holds.updated_at = …` to every UPDATE and MySQL rejects it with 1054 —
     * which is how `OrderService::cancelOrder()` failed, releasing the holds of a
     * cancelled order. Same remedy as `OrderItem`.
     */
    public const UPDATED_AT = null;

    protected $table = 'seat_holds';

    protected $fillable = [
        'public_id',
        'inventory_item_id',
        'cart_id',
        'session_id',
        'quantity',
        'expires_at',
        'released_at',
        'converted_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Check if hold is still active (not released or converted)
     */
    public function isActive(): bool
    {
        return $this->released_at === null && $this->converted_at === null;
    }

    /**
     * Check if hold has expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if hold can be converted to order.
     *
     * Тонкая обёртка над `HoldGrace`: само правило («`expires_at` плюс
     * grace-окно») живёт в `Nabilet\Core\Support\HoldGrace`, а не здесь. Раньше
     * метод повторял арифметику окна и условие `isFuture() || now()->lt(…)` —
     * это была вторая копия правила, которая могла разойтись с
     * `SeatHoldLifecycle` и с sweeper'ом. Расхождение стоило бы покупателю
     * места, за которое он уже заплатил.
     *
     * Сейчас метод никем не вызывается: проверку в момент оплаты делает
     * `SeatHoldLifecycle::isHoldConvertible()`. Оставлен как выражение того же
     * правила на уровне модели. Если потребитель так и не появится — удалить
     * (см. docs/CODE-QUALITY-GUIDE.md §9, P1.9.9).
     */
    public function isConvertible(): bool
    {
        return $this->isActive() && HoldGrace::isWithinGrace($this->expires_at);
    }

    /**
     * Grace-окно после истечения холда, в минутах.
     *
     * Делегат к `HoldGrace::minutes()`. Ключ конфига читается ровно в одном
     * месте — иначе у sweeper'а, вебхука и модели появилось бы три разных
     * значения. Оставлен как публичная точка входа для кода, которому нужно
     * только число.
     */
    public static function graceMinutes(): int
    {
        return HoldGrace::minutes();
    }
}
