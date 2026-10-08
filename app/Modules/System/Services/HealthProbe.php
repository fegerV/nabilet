<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Проверка работоспособности установки — единственный источник истины о том,
 * что значит «система здорова».
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ КЛАСС
 *   Тот же вопрос задают два входа с разной аудиторией: публичный
 *   `GET /api/v1/health` (монитор) и админский `GET /api/v1/admin/system/status`
 *   (диагностика). Если бы каждый считал статус сам, мы получили бы ровно тот
 *   класс дефекта, который в этом проекте уже ловили не раз: два места
 *   отвечают на один вопрос по-разному, и расходятся они молча. Поэтому
 *   решает здесь только этот класс, а контроллеры лишь оформляют ответ.
 *
 * КРИТИЧНОСТЬ ЗАВИСИМОСТЕЙ
 *   `database` критична: без неё не работает ничего.
 *   `redis` и `queue` — нет. `.env.example` объявляет Redis опциональным
 *   («Never make the system depend on it»), а `docs/PLAN.md` (6.5) требует
 *   «деградация Redis не роняет систему». Отказ необязательной зависимости не
 *   должен выводить живой узел из ротации балансировщика. Остановка очереди
 *   задержит выпуск билетов и письма, но продажи продолжаются — это
 *   `degraded`, и ловить его надо алертом на значение `status`, а не на код
 *   ответа.
 */
final class HealthProbe
{
    public const OK = 'ok';

    public const DEGRADED = 'degraded';

    public const DOWN = 'down';

    /**
     * Зависимость не используется в текущей конфигурации — это не ошибка.
     * Отдельное значение, а не `ok`, чтобы монитор не считал непроверенное
     * проверенным.
     */
    public const NOT_CONFIGURED = 'not_configured';

    public const ERROR = 'error';

    /**
     * @return array<string, array{state: string, critical: bool}>
     */
    public function checks(): array
    {
        return [
            'database' => $this->database(),
            'redis' => $this->redis(),
            'queue' => $this->queue(),
        ];
    }

    /**
     * Итоговый статус по спеке: `ok` | `degraded` | `down`.
     */
    public function status(): string
    {
        return self::statusFor($this->checks());
    }

    /**
     * Код ответа для `GET /api/v1/health`: 503 только на критичном отказе.
     */
    public function httpCode(): int
    {
        return self::httpCodeFor($this->status());
    }

    /**
     * Правило приоритета, вынесенное в чистую функцию.
     *
     * Считается именно здесь, а не по месту вызова: два входа (публичный
     * `/health` и админский `/admin/system/status`) обязаны получать ОДИН
     * вердикт. Чистая функция ещё и позволяет проверить правило без базы и
     * без Redis — иначе «down перекрывает degraded» проверялось бы только
     * отключением MySQL.
     *
     * Критичный отказ перекрывает деградацию независимо от порядка проверок,
     * чтобы `down` не мог замаскироваться под `degraded`.
     *
     * @param  array<string, array{state: string, critical: bool}>  $checks
     */
    public static function statusFor(array $checks): string
    {
        $degraded = false;

        foreach ($checks as $check) {
            if ($check['state'] === self::OK || $check['state'] === self::NOT_CONFIGURED) {
                continue;
            }

            if ($check['critical']) {
                return self::DOWN;
            }

            $degraded = true;
        }

        return $degraded ? self::DEGRADED : self::OK;
    }

    public static function httpCodeFor(string $status): int
    {
        return $status === self::DOWN ? 503 : 200;
    }

    /**
     * @return array{state: string, critical: bool}
     */
    public function database(): array
    {
        try {
            // Именно запрос, а не `getPdo()`: соединение может быть выдано из
            // пула без реального обращения к серверу, и тогда «соединение
            // установлено» — неправда. `select 1` доходит до сервера.
            DB::connection()->select('select 1');

            return ['state' => self::OK, 'critical' => true];
        } catch (\Throwable) {
            return ['state' => self::ERROR, 'critical' => true];
        }
    }

    /**
     * @return array{state: string, critical: bool}
     */
    public function queue(): array
    {
        $connection = (string) config('queue.default', 'sync');

        // `sync` выполняет джобу в том же запросе, `null` — выбрасывает её:
        // проверять нечего.
        if ($connection === 'sync' || $connection === 'null') {
            return ['state' => self::NOT_CONFIGURED, 'critical' => false];
        }

        try {
            Queue::connection($connection)->size();

            return ['state' => self::OK, 'critical' => false];
        } catch (\Throwable) {
            return ['state' => self::ERROR, 'critical' => false];
        }
    }

    /**
     * @return array{state: string, critical: bool}
     */
    public function redis(): array
    {
        if (! $this->redisInUse()) {
            return ['state' => self::NOT_CONFIGURED, 'critical' => false];
        }

        try {
            Cache::store('redis')->put('health_probe', 'ok', 10);
            $value = Cache::store('redis')->get('health_probe');

            return ['state' => $value === 'ok' ? self::OK : self::ERROR, 'critical' => false];
        } catch (\Throwable) {
            return ['state' => self::ERROR, 'critical' => false];
        }
    }

    /**
     * Redis проверяем только если он действительно где-то задействован.
     * Иначе установка без Redis (штатная конфигурация, `CACHE_STORE=file`)
     * вечно показывала бы ошибку, и алерт на неё обесценился бы.
     */
    private function redisInUse(): bool
    {
        $cacheStore = config('cache.default');

        if (is_string($cacheStore) && config("cache.stores.{$cacheStore}.driver") === 'redis') {
            return true;
        }

        return config('queue.default') === 'redis' || config('session.driver') === 'redis';
    }
}
