<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Защита от небезопасных production-конфигураций (ТЗ §79).
 *
 * Значения по умолчанию в шаблоне окружения (`APP_DEBUG=true`,
 * `PAYMENT_DEMO_MODE=true`, `TRUSTED_PROXIES=*`) опасны ровно тогда, когда их
 * копируют в продакшен не задумываясь, — а именно так и деплоят по инструкции
 * «скопируйте `.env.example` в `.env`». Каждое из них означает конкретный
 * инцидент:
 *
 *  - `APP_DEBUG=true`: любое необработанное исключение возвращает страницу со
 *    стектрейсом, SQL-запросами, фрагментами исходников и переменными окружения;
 *  - `PAYMENT_DEMO_MODE=true`: `POST /payments/demo-pay` подтверждает оплату
 *    анонимным запросом — билет выдаётся без денег;
 *  - `TRUSTED_PROXIES=*`: `X-Forwarded-For` от кого угодно, поэтому лимитер
 *    считает каждый запрос новым IP (брутфорс паролей), а IP-allowlist вебхуков
 *    перестаёт что-либо значить (подделка уведомлений об оплате).
 *
 * Fail-closed выбран сознательно: приложение, которое не поднялось, дешевле
 * приложения, которое утекло. Проверка срабатывает только в `production`, поэтому
 * локальная разработка, тесты и установщик (он пишет `APP_ENV=production` уже с
 * безопасными значениями) не затронуты.
 */
final class ProductionSafetyProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $violations = [];

        if ((bool) config('app.debug')) {
            $violations[] = 'APP_DEBUG=true: страницы ошибок раскрывают стектрейсы, SQL '
                . 'и переменные окружения. Установите APP_DEBUG=false.';
        }

        if ((bool) config('nabilet.payment.demo_mode')) {
            $violations[] = 'PAYMENT_DEMO_MODE=true: POST /payments/demo-pay подтверждает '
                . 'оплату анонимным запросом. Установите PAYMENT_DEMO_MODE=false.';
        }

        if (in_array('*', $this->trustedProxies(), true)) {
            $violations[] = 'TRUSTED_PROXIES=*: доверие X-Forwarded-* от любого источника '
                . 'обнуляет rate-limit и IP-allowlist вебхуков. Укажите точные CIDR '
                . 'балансировщика.';
        }

        if ($this->usesDatabaseCache() && ! $this->databaseCacheAcknowledged()) {
            $violations[] = 'CACHE_STORE=database: счётчики rate-limit (`throttle:auth` — '
                . '5 попыток входа в минуту) пишутся в ту же MySQL, которая обслуживает '
                . 'каталог и заказы. Брутфорс паролей превращается в нагрузку на '
                . 'бизнес-транзакции, а под нагрузкой лимитер деградирует вместе с БД — '
                . 'то есть защита слабеет ровно тогда, когда нужна. Установите '
                . 'CACHE_STORE=redis или подтвердите осознанный выбор хостинга без Redis: '
                . 'NABILET_ALLOW_DATABASE_CACHE=true.';
        }

        foreach ($this->wildcardWebhookAllowlists() as $key) {
            $violations[] = "{$key}=*: для ЮKassa allowlist — единственный слой проверки "
                . 'уведомлений (HMAC она не подписывает), поэтому звёздочка означает '
                . '«подтвердить оплату может кто угодно». Укажите официальные CIDR провайдера.';
        }

        if ($violations !== []) {
            throw new RuntimeException(
                "Unsafe production configuration:\n  - " . implode("\n  - ", $violations)
            );
        }
    }

    /**
     * `TRUSTED_PROXIES` читается так же, как в `bootstrap/app.php` (`env()`), —
     * иначе проверка смотрела бы не на то значение, которым пользуется
     * middleware.
     *
     * @return list<string>
     */
    private function trustedProxies(): array
    {
        $raw = (string) (env('TRUSTED_PROXIES') ?? '');

        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
    }

    /**
     * Провайдеры, у которых IP-allowlist вебхуков содержит `*`.
     *
     * Список читается через `config()`, а не `env()`: именно `config()`
     * использует `WebhookAuthenticator`. Имена переменных совпадают с
     * `config/nabilet.php`, поэтому сообщение называет то, что оператор
     * действительно правит в `.env`.
     *
     * @return list<string>
     */
    private function wildcardWebhookAllowlists(): array
    {
        $violations = [];

        foreach ((array) config('nabilet.payment.providers', []) as $provider) {
            $provider = trim((string) $provider);

            if ($provider === '') {
                continue;
            }

            $allowlist = (array) config("nabilet.payment.{$provider}.webhook_ip_allowlist", []);

            if (in_array('*', $allowlist, true)) {
                $violations[] = 'YOOKASSA_WEBHOOK_IP_ALLOWLIST';
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * Кэш по умолчанию лежит в той же БД, что и бизнес-данные.
     *
     * Через `config('cache.default')`, а не `env('CACHE_STORE')`: именно это
     * значение выбирает стор, которым пользуется `RateLimiter`, и проверка
     * обязана смотреть на то же, чем пользуется приложение. `config/cache.php`
     * подставляет `database` как fallback, поэтому пустая переменная в `.env`
     * тоже даёт `database` — и должна попадать под проверку.
     */
    private function usesDatabaseCache(): bool
    {
        return (string) config('cache.default') === 'database';
    }

    /**
     * Оператор явно подтвердил, что Redis на хостинге нет.
     *
     * Отказ «всегда падать на database» сломал бы задокументированный сценарий
     * хостинга без Redis (см. комментарий в `config/cache.php`). Поэтому проверка
     * fail-closed по умолчанию, но с осознанным opt-out: выбор должен быть
     * записан в `.env`, а не подразумеваться.
     */
    private function databaseCacheAcknowledged(): bool
    {
        return filter_var(env('NABILET_ALLOW_DATABASE_CACHE', false), FILTER_VALIDATE_BOOL);
    }
}
