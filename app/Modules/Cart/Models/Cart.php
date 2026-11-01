<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $cart_token гостевой токен покупателя (X-Cart-Token) — фикс D5
 * @property string $session_id
 * @property string $status 'active' | 'abandoned' | 'converted'
 * @property string $currency
 * @property string $total_amount
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class Cart extends Model
{
    use TenantScoped;
    use HasPublicId;

    protected $table = 'carts';

    protected $fillable = [
        'user_id',
        'cart_token',
        'session_id',
        'status',
        'currency',
        'total_amount',
        'expires_at',
        'reminder_sent_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'total_amount' => 'string',
    ];

    /**
     * Глобальный читающий скоуп (двенадцатая модель после Order, Payment,
     * Ticket, Refund, TicketScan, CheckinDevice, OfflineBundle,
     * PaymentTransaction, OrderItem, SeatHold и CartItem).
     *
     * Запись и так защищена трейтом (организация выводится из сеанса через
     * TenantResolver; фаза 3 сделала колонку NOT NULL): корзину чужой
     * организации создать нельзя.
     *
     * Чтение живёт по capability токена корзины (`cart_token`), а не по
     * организации HTTP-запроса: гости работают без тенант-контекста, владение
     * корзиной объявляется фильтром по токену/`user_id` внутри запроса. Пути
     * в нужном режиме:
     *   - витрина (`CartController`, включая `ownedCart`), полная очистка
     *     и `getOrCreateCart` (`CartService`) — в bypass;
     *   - сервис позиций (`CartItemService`: удаление позиции, продление
     *     холда) и чекаут (`CartCheckoutService`) — в bypass;
     *   - проверка права оплаты по токену (`OrderController`,
     *     `PaymentController`, `EmbedController::callerHoldsCart`) — в bypass;
     *   - промокоды по корзине (`PromoCodeController::resolveCart`) — в bypass;
     *   - embed-путь (`EmbedAccessService::findByReference` — lookup,
     *     решение о доступе после) — в bypass;
     *   - пересчёт цен активных корзин (`InventoryService`) — системный
     *     путь в bypass; sweeper просроченных заказов (`StaleOrderExpirer`)
     *     уже целиком в bypass с шага `Order`.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    public function user(): BelongsTo
        {
            return $this->belongsTo(User::class);
        }

        public function session(): BelongsTo
        {
            return $this->belongsTo(\Nabilet\Modules\Sessions\Models\Session::class, 'session_id');
        }

        public function items(): HasMany
        {
            return $this->hasMany(CartItem::class);
        }
    }
