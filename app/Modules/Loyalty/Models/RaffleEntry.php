<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Билет розыгрыша — оплаченный заказ внутри окна (фаза 5).
 *
 * БИЛЕТ — ЭТО ЗАКАЗ, А НЕ ЧЕЛОВЕК
 *   Покупатель по умолчанию — гость без аккаунта: «одна строка на человека»
 *   потеряла бы всех, кто так и не завёл аккаунт. Заказ есть у обоих, и в
 *   нём есть адрес (`orders.customer_email`, NOT NULL), по которому
 *   победителя находят. Поэтому ключ идемпотентности — `UNIQUE (raffle_id,
 *   order_id)`, а не `(raffle_id, user_id)`.
 *
 * ИДЕМПОТЕНТНОСТЬ — В СХЕМЕ, А НЕ В ПРОВЕРКЕ
 *   Запись создаётся из события оплаты, а событие повторится: обсервер
 *   срабатывает на `updated`, `markPaid()` идемпотентен, джоба повторяется по
 *   `tries`. Проверка «есть ли строка?» отдельным SELECT ненадёжна при двух
 *   одновременных оплатах, поэтому `remember()` ловит конфликт вставки по
 *   `uq_raffle_entries_order` и считает его «билет уже есть» — тем же
 *   приёмом, что `Referral::upsertByEmail()` и `FollowNotification::claim()`.
 *
 * ЗАПИСЬ НЕ ПРАВИТСЯ
 *   Ни `updated_at`, ни мягкого удаления: участие — факт, а не состояние.
 *   Возврат заказа билет НЕ отзывает (ограничение записано в
 *   `docs/LOYALTY-PLAN.md` и в шапке сплита 022), поэтому и движений у
 *   строки нет.
 */
class RaffleEntry extends Model
{
    /**
     * В таблице только `created_at` — запись не правится после создания.
     *
     * `UPDATED_AT = null` отключает обслуживание `updated_at`, оставляя
     * автоматическим `created_at`: запись участия — факт, а не
     * состояние, и колонки под её правки в схеме нет намеренно.
     */
    public const UPDATED_AT = null;

    protected $table = 'raffle_entries';

    protected $fillable = [
        'public_id',
        'raffle_id',
        'order_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RaffleEntry $entry): void {
            if ($entry->public_id === null) {
                $entry->public_id = Str::ulid()->toBase32();
            }
        });
    }

    /** @return BelongsTo<Raffle, $this> */
    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class, 'raffle_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Билет заказа в розыгрыше — ровно один, сколько бы раз ни звали.
     *
     * @return RaffleEntry|null null — билет уже есть (повтор), иначе строка
     */
    public static function remember(int|string $raffleId, Order $order): ?RaffleEntry
    {
        try {
            return self::create([
                'raffle_id' => $raffleId,
                'order_id' => $order->getKey(),
                // Снимок покупателя на момент входа: заказ к моменте оплаты
                // уже прошёл `resolveBuyerId()`, поэтому `user_id` финален.
                'user_id' => $order->user_id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Билет уже есть — это нормальный путь, а не авария.
            return null;
        }
    }

    /** @return Builder<RaffleEntry> */
    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
