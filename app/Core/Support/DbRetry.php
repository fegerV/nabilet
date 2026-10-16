<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

use Illuminate\Database\QueryException;

/**
 * Повтор транзакций при взаимоблокировке MySQL (аудит производительности 1.3 и
 * D4-arch: разные порядки захвата блокировок между addItem/checkout/sweeper
 * теоретически дают 1213, обрабатываемого как исход «попробуйте снова»).
 *
 * КАК ЭТО РАБОТАЕТ
 *   Deadlock — спор о порядке, а не о испорченных данных: обе транзакции
 *   откатываются, окна освобождаются. Повтор целой транзакции безопасен
 *   (all-or-nothing) и устраняет ложный 500 для клиента. До этого helper'а не
 *   было: одиночный deadlock улетал 500-ей прямо в чекаут.
 *
 * ЧТО ПОВТОРЯЕТСЯ
 *   - SQLSTATE 40001 (serialization failure/deadlock);
 *   - код драйвера 1213 (InnoDB deadlock);
 *   - 1205 (lock wait timeout) — тоже спорное окно; повтор безопасен, но
 *     ограничен тем же лимитом попыток.
 *
 * Ограничение попыток и небольшая пауза с ростом (50 мс → 100 мс → 150 мс)
 * подобраны так, чтобы пик продаж не устроил шторм повторов: 3 попытки —
 * стенд-арт из документов InnoDB.
 *
 * НЕ использовать для функций с внешними побочными эффектами (вызовы
 * платёжного провайдера): отражение их невозможно, идемпотентность на том
 * уровне своя (см. initiatePayment).
 */
final class DbRetry
{
    public const MAX_ATTEMPTS = 3;

    /**
     * @template T
     *
     * @param  callable(): T $fn транзакция целиком; любой бросок с ожиданием
     *                         отката внутри подходит
     * @return T
     */
    public static function run(callable $fn): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $fn();
            } catch (QueryException $e) {
                if ($attempt >= self::MAX_ATTEMPTS || ! self::isRetryable($e)) {
                    throw $e;
                }

                usleep(50_000 * $attempt);
            }
        }
    }

    public static function isRetryable(QueryException $e): bool
    {
        $info = $e->errorInfo ?? [];
        $sqlState = (string) ($info[0] ?? '');
        $driverCode = (string) ($info[1] ?? '');

        return $sqlState === '40001'
            || $driverCode === '1213'
            || $driverCode === '1205';
    }
}
