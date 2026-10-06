<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Grace-окно холда места — ЕДИНСТВЕННЫЙ источник числа и всей арифметики вокруг
 * него.
 *
 * Зачем отдельный класс. Число «сколько ещё секунд после `expires_at` платёж
 * считается валидным» раньше было продублировано литералом `addMinutes(5)` /
 * `subMinutes(5)` в пяти местах трёх модулей. Копии не были связаны друг с
 * другом, и расхождение между ними — не косметика: sweeper вернул бы место в
 * продажу, пока вебхук оплаты ещё считает холд живым, и покупатель, нажавший
 * «оплатить» за секунду до истечения, потерял бы место, уже оплатив его.
 *
 * Сейчас число читается ровно здесь — из `nabilet.checkout.hold_grace_minutes`
 * (`CHECKOUT_HOLD_GRACE_MINUTES`, по умолчанию 5 минут). Потребители не должны
 * читать конфиг сами и не должны повторять арифметику `± grace`: они обязаны
 * вызывать методы этого класса. Инвариант закреплён тестом
 * `tests/Unit/HoldGraceTest.php`, который падает, если ключ конфига появляется
 * во втором файле или если в потребителе снова возникает литерал.
 *
 * Направление времени у разных потребителей противоположное, поэтому методов
 * два и они названы по смыслу, а не по знаку:
 *  - `cutoff()`     — момент, РАНЬШЕ которого холд считается просроченным
 *                     (`now − grace`). Нужен выборкам sweeper'а: «всё, что
 *                     истекло до этой отметки, можно снимать».
 *  - `endsAt()`     — момент, ПОЗЖЕ которого холд уже нельзя конвертировать
 *                     (`expires_at + grace`). Нужен проверкам при оплате.
 *  - `isWithinGrace()` — предикат для последнего случая, чтобы потребители не
 *                     сравнивали даты вручную (и не ошибались знаком).
 */
final class HoldGrace
{
    /**
     * Длина grace-окна в минутах. Не может быть отрицательной: отрицательный
     * grace означал бы «холд перестаёт быть валидным ДО своего `expires_at`»,
     * то есть оплата в последнюю секунду отклонялась бы при живой брони.
     */
    public static function minutes(): int
    {
        return max(0, (int) config('nabilet.checkout.hold_grace_minutes', 5));
    }

    /**
     * Отметка, раньше которой истёкший холд считается окончательно просроченным
     * (`now − grace`). Именно её сравнивают с `seat_holds.expires_at` и
     * `carts.expires_at` запросы sweeper'а — чтобы не снять холд раньше, чем
     * закроется grace-окно оплаты.
     *
     * `$now` передаётся явно там, где он уже зафиксирован в начале операции:
     * иначе два вызова `now()` внутри одной транзакции могли бы дать разные
     * отметки.
     */
    public static function cutoff(?CarbonInterface $now = null): CarbonImmutable
    {
        return self::reference($now)->subMinutes(self::minutes());
    }

    /**
     * Момент, после которого холд с данным `expires_at` конвертировать нельзя
     * (`expires_at + grace`).
     */
    public static function endsAt(CarbonInterface $expiresAt): CarbonImmutable
    {
        return CarbonImmutable::instance($expiresAt)->addMinutes(self::minutes());
    }

    /**
     * Жив ли холд с таким `expires_at` с точки зрения grace-окна.
     *
     * Обратите внимание: отдельная проверка «`expires_at` в будущем» здесь не
     * нужна и была бы лишней. При неотрицательном grace всегда
     * `now < expires_at ≤ expires_at + grace`, поэтому ветка «ещё не истёк»
     * уже покрыта сравнением с `endsAt()`. Именно это расхождение — два условия
     * против одного — и было в прежних копиях правила.
     */
    public static function isWithinGrace(CarbonInterface $expiresAt, ?CarbonInterface $now = null): bool
    {
        return self::reference($now)->lt(self::endsAt($expiresAt));
    }

    /**
     * `CarbonImmutable`-представление переданного момента, либо «сейчас».
     */
    private static function reference(?CarbonInterface $now): CarbonImmutable
    {
        return $now === null ? CarbonImmutable::now() : CarbonImmutable::instance($now);
    }
}
