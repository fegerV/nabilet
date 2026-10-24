<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Models;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Orders\Models\Order;

/**
 * Движение по бонусному счёту — append-only журнал.
 *
 * СТРОКА ЖУРНАЛА НЕ МЕНЯЕТСЯ НИКОГДА
 *   Поэтому у модели нет `updated_at` (`UPDATED_AT = null`) и нет ни одного
 *   метода вида `setDelta()`. Компенсация при возврате — это НОВАЯ строка с
 *   обратным знаком, а не правка старой: «почему у меня 340 бонусов» должен
 *   объяснять набор строк, и правка задним числом уничтожила бы это объяснение.
 *
 * ЗНАК — В `delta`, ПРИЧИНА — В `reason`
 *   `delta` знаковый (плюс — начислили, минус — списали, вернули, сожгли по
 *   сроку), `reason` называет причину из словаря `BonusReason`. Одна колонка на
 *   оба смысла потеряла бы один из них: `reason` без знака не отличает
 *   начисление от отката, знак без `reason` не отличает кэшбэк от подарка за
 *   регистрацию.
 *
 * `dedupe_key` — идемпотентность, объявляемая писателем
 *   Начисление обязано пройти ровно один раз на заказ, и «SELECT, потом INSERT»
 *   здесь не годится — между ними влезает второй воркер. Уникальный ключ
 *   `uq_bonus_transactions_dedupe` превращает повтор в конфликт вставки.
 *   Колонка НЕОБЯЗАТЕЛЬНАЯ намеренно: частичный возврат — законная вторая
 *   компенсация по тому же заказу, и `UNIQUE (order_id, reason)` сделал бы её
 *   невозможной. Строки без ключа (возвраты) друг другу не мешают, потому что
 *   MySQL допускает сколько угодно NULL в UNIQUE.
 */
class BonusTransaction extends Model
{
    use HasPublicId;

    protected $table = 'bonus_transactions';

    protected $fillable = [
        'public_id',
        'user_id',
        'order_id',
        'delta',
        'reason',
        'dedupe_key',
    ];

    /** Журнал append-only: строки не правятся, значит и `updated_at` не существует. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BonusTransaction $transaction): void {
            if ($transaction->created_at === null) {
                $transaction->created_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Заказ, из-за которого появилось движение. `null` — у подарков
     * (`registration`, `first_purchase`) и у истечения срока: они не привязаны
     * к заказу.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * История движений покупателя, свежие сверху.
     *
     * Это ровно тот запрос, под который создан `idx_bonus_transactions_user_created`
     * (сплит 019): «покажи мои бонусы» всегда означает «все строки одного
     * человека в обратном времени».
     *
     * @return Builder<BonusTransaction>
     */
    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId)->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @return Builder<BonusTransaction> */
    public function scopeForOrder(Builder $query, int|string $orderId): Builder
    {
        return $query->where('order_id', $orderId);
    }
}
