<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Inventory\Models\InventoryItem;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $inventory_item_id
 * @property int $quantity
 * @property string $unit_price
 * @property string $total_price
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class CartItem extends Model
{
    use TenantScoped;
    protected $table = 'cart_items';

    /**
     * Глобальный читающий скоуп (одиннадцатая модель после Order, Payment,
     * Ticket, Refund, TicketScan, CheckinDevice, OfflineBundle,
     * PaymentTransaction, OrderItem и SeatHold).
     *
     * Запись и так защищена трейтом (организация выводится из корзины через
     * TenantResolver): `CartItem::create` гостя не имеет контекста —
     * организация приходит из родительской корзины, «в чужую корзину» не
     * записаться.
     *
     * Чтение живёт по capability токена корзины, а не по организации
     * HTTP-запроса: позиция — ребёнок корзины гостя, идентифицированной
     * `cart_token`. Пути уже в нужном режиме:
     *   - витрина (`CartController`) и полная очистка корзины
     *     (`CartService`) — в bypass;
     *   - чекаут (`CartCheckoutService`: поиск корзины с eager `items`,
     *     по токену или `public_id`) — в bypass, дальше связи кэшированы;
     *   - пересчёт цен открытых корзин (`InventoryService`) — системный
     *     путь, в bypass по явному списку позиций склада;
     *   - `CartItemService` (добавление/удаление/продление/пересчёт) —
     *     в bypass как children-only пути гостя.
     * Позиция не адресуется в маршрутах — route-binding не нужен.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected $fillable = [
        'cart_id',
        'inventory_item_id',
        'quantity',
        'unit_price',
        'total_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'string',
        'total_price' => 'string',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
