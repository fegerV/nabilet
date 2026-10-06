<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Support\HoldGrace;

/**
 * SeatHoldLifecycle — судьба ОДНОГО холда в момент оплаты.
 *
 * Отделено от `HoldSweeper` (P2). Раньше оба сценария жили в одном классе
 * `HoldSweeper`, хотя у них нет ни общих данных, ни общего вызывающего:
 *
 *  - `HoldSweeper` — ПАКЕТНАЯ работа по Cron: раз в минуту пройти по всем
 *    истёкшим холдам и вернуть места в продажу. Работает вне HTTP-запроса,
 *    никого не ждёт, идемпотентен, ошибки пишет в лог.
 *  - этот класс — ТОЧЕЧНАЯ проверка/переход в рамках обработки вебхука оплаты:
 *    «этот конкретный холд ещё можно конвертировать?» и «отметить
 *    конвертированным». Вызывается из `Payments\Services\PaymentService` в
 *    момент, когда покупатель уже заплатил, и от ответа зависит, потеряет он
 *    место или нет.
 *
 * Совмещение этих двух ролей в одном классе и было источником дефекта
 * «оплата в последнюю секунду»: sweeper и вебхук сравнивали время по-разному.
 * Теперь обе стороны берут окно из одного места — `Nabilet\Core\Support\HoldGrace`.
 *
 * ВАЖНО: класс читает `seat_holds` через query builder (`DB::table`), а не
 * через Eloquent-модель, и делает это намеренно — см. докблок `isHoldConvertible()`.
 */
final class SeatHoldLifecycle
{
    /**
     * Можно ли ещё конвертировать холд (то есть считать оплату по нему
     * валидной).
     *
     * Вызывается при обработке вебхука оплаты, поэтому обязан быть устойчив к
     * гонкам:
     *  - читает строку по `id` в рамках текущей транзакции вызывающего кода;
     *  - «уже снят/уже конвертирован» → `false`: параллельный sweeper успел
     *    вернуть место в продажу, и принимать за него деньги нельзя;
     *  - окно проверяется через `HoldGrace::isWithinGrace()` — то же число, что
     *    читает sweeper, поэтому «оплатил за секунду до истечения» не попадает
     *    в расхождение двух реализаций.
     *
     * Возвращает `false` и для несуществующего холда: вызывающий код не должен
     * различать «холда нет» и «холд просрочен» — в обоих случаях конвертировать
     * нечего.
     *
     * @param int $holdId идентификатор строки `seat_holds`
     * @return bool true, если холд ещё жив и его можно превратить в продажу
     */
    public function isHoldConvertible(int $holdId): bool
    {
        $hold = DB::table('seat_holds')
            ->where('id', $holdId)
            ->first();

        if ($hold === null) {
            return false;
        }

        // Уже снят или уже сконвертирован — второй раз не конвертируем.
        if ($hold->converted_at !== null || $hold->released_at !== null) {
            return false;
        }

        return HoldGrace::isWithinGrace(CarbonImmutable::parse($hold->expires_at));
    }

    /**
     * Пометить холд сконвертированным (успешная оплата).
     *
     * Условия `whereNull('converted_at')` / `whereNull('released_at')` — часть
     * самого UPDATE, а не отдельного чтения: два параллельных вебхука по одному
     * платежу не должны оба получить `true`. Второй увидит `affected = 0` и
     * поймёт, что холд уже обработан.
     *
     * `seat_holds` не имеет `updated_at` (см. `Orders\Models\SeatHold::UPDATED_AT`),
     * поэтому здесь используется query builder, а не `$hold->update()` — Eloquent
     * добавил бы в UPDATE несуществующую колонку и MySQL ответил бы ошибкой 1054.
     *
     * @param int $holdId идентификатор строки `seat_holds`
     * @return bool true, если именно этот вызов перевёл холд в converted
     */
    public function markAsConverted(int $holdId): bool
    {
        $affected = DB::table('seat_holds')
            ->where('id', $holdId)
            ->whereNull('converted_at')
            ->whereNull('released_at')
            ->update([
                'converted_at' => CarbonImmutable::now()->toDateTimeString(),
            ]);

        return $affected > 0;
    }
}
