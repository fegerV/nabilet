<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Models;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Domain\ReferralCode as ReferralCodeRules;

/**
 * Код приглашения: адресная ссылка `/r/{code}` приглашающего.
 *
 * ОДНА СТРОКА НА ЧЕЛОВЕКА
 *   `UNIQUE (user_id)` — свойство приглашающего, а не каждой отправленной
 *   ссылки: два кода у одного человека сделали бы вопрос «какая ссылка моя» в
 *   кабинете неразрешимым, а `/r/{code}` — неоднозначным. Смена кода — это
 *   UPDATE одной строки, и все выданные ссылки старого кода перестают
 *   разрешаться разом.
 *
 * ГОНКА ЗАКРЫТА УНИКАЛЬНЫМ КЛЮЧОМ, А НЕ ПРОВЕРКОЙ «УЖЕ ЕСТЬ?»
 *   Два одновременных запроса кода (две вкладки кабинета) прошли бы проверку
 *   оба. `uq_referral_codes_user` роняет лишнюю вставку, а
 *   `uq_referral_codes_code` — ещё более редкий случай совпадения сгенерированных
 *   кодов (80 бит энтропии, но проверять дешевле, чем объясняться). Тот же
 *   приём, что у `BonusAccount::forUserOrCreate()`.
 */
class ReferralCode extends Model
{
    use HasPublicId;

    protected $table = 'referral_codes';

    protected $fillable = [
        'public_id',
        'user_id',
        'code',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Код приглашения покупателя, создаваемый при первом обращении.
     *
     * Ленивый, а не при регистрации: покупатель по умолчанию — гость, и
     * заводить код каждому, кто открыл афишу, значило бы держать таблицу
     * ссылок, которыми никто не пользуется.
     *
     * ПОЧЕМУ `firstOrCreate`, А НЕ СВОЙ `try/catch`. Раньше стояла пара
     * «SELECT → create → catch → SELECT», то есть переписанный
     * `Builder::firstOrCreate()`, который делает ровно это и вдобавок
     * оборачивает вставку в `withSavepointIfNeeded()`.
     *
     * ПОЧЕМУ `catch` ВСЁ ЖЕ ОСТАЛСЯ — второй уникальный индекс. У таблицы их
     * два: `uq_referral_codes_user` и `uq_referral_codes_code`. `firstOrCreate`
     * переспрашивает по `user_id`; если нарушился ключ по `code` (совпал
     * сгенерированный код у ДРУГОГО человека), переспрос ничего не найдёт и
     * `createOrFirst()` перевыбросит исключение. Этот `catch` — не про гонку, а
     * про повтор генерации: одна дополнительная попытка, и только одна.
     */
    public static function forUserOrCreate(int|string $userId): self
    {
        try {
            return self::query()->firstOrCreate(
                ['user_id' => $userId],
                ['code' => ReferralCodeRules::generate()],
            );
        } catch (UniqueConstraintViolationException) {
            // Вложенность одна: при втором конфликте исключение уйдёт наверх,
            // потому что дважды подряд попасть в 80 бит энтропии означает не
            // случайность, а поломку генератора.
            return self::query()->firstOrCreate(
                ['user_id' => $userId],
                ['code' => ReferralCodeRules::generate()],
            );
        }
    }

    /** @return Builder<ReferralCode> */
    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /** @return Builder<ReferralCode> */
    public function scopeWithCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }
}
