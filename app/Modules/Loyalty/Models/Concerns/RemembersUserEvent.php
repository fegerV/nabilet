<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Models\Concerns;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Идемпотентная запись «пользователь ↔ событие».
 *
 * ЗАЧЕМ. `EventFavorite::remember()` и `EventFollow::subscribe()` были
 * побайтово одинаковыми методами в двух параллельных моделях: обе — связка
 * «пользователь ↔ событие» с уникальным ключом на пару (`uq_event_favorites_user_event`,
 * `uq_event_follows_user_event`), и обе обязаны отвечать на повторное нажатие
 * тем же фактом, а не второй строкой и не ошибкой.
 *
 * ПОЧЕМУ ЭТО НЕ БЕЗОБИДНО, ХОТЯ И НЕ ПАДАЕТ. Докблок `EventFollow` говорил
 * «та же защита от гонки, что у `EventFavorite::remember()`» — то есть
 * совпадение двух реализаций держалось на предложении. Исправить гонку в одной
 * модели и не вспомнить вторую — значит оставить в ней ровно ту ошибку, ради
 * которой правку затевали. `BonusAccount` ссылался на них же третьим
 * докблоком: три модели, три реализации, два перекрёстных обещания.
 *
 * ПОЧЕМУ `firstOrCreate` НЕ ПОДХОДИТ. Между его SELECT и INSERT влезает второй
 * запрос, и уникальный ключ роняет вставку дубликата. Ловим именно нарушение
 * ключа и возвращаем уже существующую строку: повторное нажатие отвечает 200,
 * а не 500.
 *
 * ПОЧЕМУ `firstOrFail`, А НЕ `first()` + проверка. В продакшене `assert()`
 * выключен, и `assert()` молча вернул бы null из метода, который обещает
 * `static`. `firstOrFail()` бросает всегда.
 *
 * NULL в ветке конфликта невозможен по построению: ключ нарушился, значит
 * строка есть — её только что вставил кто-то другой, ровно между нашими SELECT
 * и INSERT.
 */
trait RemembersUserEvent
{
    /**
     * Повторное действие — тот же факт, а не вторая строка и не ошибка.
     */
    public static function remember(int|string $userId, int|string $eventId): static
    {
        try {
            return self::create([
                'user_id' => $userId,
                'event_id' => $eventId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return self::query()
                ->where('user_id', $userId)
                ->where('event_id', $eventId)
                ->firstOrFail();
        }
    }
}
