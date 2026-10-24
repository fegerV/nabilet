<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Models;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Бонусный счёт покупателя: текущий баланс, одна строка на человека.
 *
 * ПОЧЕМУ БАЛАНС ХРАНИТСЯ, ЕСЛИ ОН ЖЕ ВЫВОДИТСЯ ИЗ ЖУРНАЛА
 *   Баланс выводится из `bonus_transactions` суммой `delta`, и это проверяемо.
 *   Но витрина и кабинет показывают баланс на каждой странице, а журнал растёт
 *   неограниченно: считать `SUM(delta)` на каждый показ — это полный проход по
 *   истории покупателя ради одного числа. Колонка здесь — денормализация с
 *   одной точкой записи (`BonusService`), а не второй источник правды: журнал
 *   остаётся доказательством, баланс — кэшем этого доказательства.
 *
 * `UNIQUE (user_id)` — инвариант, а не оптимизация. Две строки на одного
 * покупателя означали бы два баланса и вопрос «какой из них настоящий», который
 * решается не в пользу покупателя. Схема это уже запрещает (`019_bonus_ledger.sql`),
 * `forUserOrCreate()` ниже рассчитан на этот ключ.
 */
class BonusAccount extends Model
{
    use HasPublicId;

    protected $table = 'bonus_accounts';

    protected $fillable = [
        'public_id',
        'user_id',
        'balance',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Счёт покупателя, создаваемый при первом обращении.
     *
     * Счёт заводится лениво, а не при регистрации: у проекта покупатель по
     * умолчанию гость, и заводить счёт каждому, кто открыл афишу, значило бы
     * держать таблицу пустых балансов. Первый заказ — вот момент, когда счёт
     * становится осмысленным.
     *
     * Гонка закрыта уникальным ключом, а не проверкой «уже есть?»: между SELECT
     * и INSERT влезает второй воркер (кэшбэк за два заказа одного покупателя
     * приходит двумя джобами), и `uq_bonus_accounts_user` роняет лишнюю вставку.
     * Тот же приём, что у `EventFollow::subscribe()`.
     */
    public static function forUserOrCreate(int|string $userId, string $currency = 'RUB'): self
    {
        $existing = self::query()->where('user_id', $userId)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return self::create([
                'user_id' => $userId,
                'balance' => 0,
                'currency' => $currency,
            ]);
        } catch (UniqueConstraintViolationException) {
            return self::query()->where('user_id', $userId)->firstOrFail();
        }
    }

    /** @return Builder<BonusAccount> */
    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
