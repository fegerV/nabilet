<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Support;

use Nabilet\Core\Support\IpRange;

/**
 * Единственный владелец правил «безопасна ли эта конфигурация для продакшена».
 *
 * Раньше правила жили внутри `ProductionSafetyProvider` и умели ровно одно:
 * бросить исключение на `boot()`. Этого недостаточно, и причина не в удобстве.
 * Провайдер срабатывает только при `APP_ENV=production`, поэтому на staging он
 * молчит: небезопасная конфигурация обнаруживается не до деплоя, а в момент,
 * когда приложение уже не поднимается, — то есть тогда, когда трафик уже
 * переключён. Команда `config:validate` вызывает те же правила явно и заранее,
 * в том числе на стенде, где `APP_ENV` ещё не `production`.
 *
 * Правила вынесены сюда, а НЕ продублированы в команде. Копия правил — это ровно
 * та болезнь, из-за которой в этом проекте разошлись три копии DDL: две копии
 * одного решения расходятся молча, и расходятся всегда. Провайдер и команда
 * обязаны отвечать на вопрос «есть ли нарушения» одинаково, поэтому ответ живёт
 * в одном месте.
 *
 * Зависимостей намеренно нет: только `config()`, `env()` и `Core\Support\IpRange`.
 * Проверка обязана смотреть на те же значения, которыми пользуется приложение, —
 * а не на собственное представление о них. `IpRange` — не новое правило, а тот
 * же разбор адресов, которым пользуется `WebhookAuthenticator`: копия разбора
 * разошлась бы с рантаймом так же, как расходятся копии DDL. Где значение
 * читается через `env()` (TRUSTED_PROXIES), это отмечено отдельно: именно так
 * его читает `bootstrap/app.php`.
 */
final class ProductionSafetyAudit
{
    /**
     * @return list<string> Описания нарушений. Пустой список — конфигурация безопасна.
     */
    public function violations(): array
    {
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

        foreach ($this->malformedWebhookAllowlists() as $key => $entries) {
            $violations[] = sprintf(
                '%s: записи %s не являются ни IP-адресом, ни CIDR. Они не участвуют в '
                . 'проверке, поэтому уведомления с этих адресов получают 403 — защита '
                . 'выглядит настроенной, но не работает. Исправьте или удалите их.',
                $key,
                implode(', ', array_map(static fn (string $entry): string => '«' . $entry . '»', $entries)),
            );
        }

        $insecureUrl = $this->insecureAppUrl();

        if ($insecureUrl !== null) {
            $violations[] = sprintf(
                'APP_URL=%s: приложение отдаёт билеты и принимает оплату, поэтому должно '
                . 'работать по HTTPS. Схема из APP_URL попадает в canonical, og:url и карту '
                . 'сайта — по http они указывают поисковику и мессенджерам на другой адрес, '
                . 'а secure-флаг сессионной cookie (config/session.php) перестаёт '
                . 'действовать. Установите APP_URL=https://<домен>.',
                $insecureUrl
            );
        }

        return $violations;
    }

    /**
     * `APP_URL` со схемой, отличной от `https`.
     *
     * `null` означает «нарушения нет», а не «значение пустое»: пустой `APP_URL`
     * — это отдельная поломка (относительные ссылки некуда резолвить), но
     * ловить её здесь нельзя, иначе проверка начнёт падать на окружениях, где
     * URL подставляется хостом запроса, и сообщение будет не о том.
     */
    private function insecureAppUrl(): ?string
    {
        $url = trim((string) config('app.url'));

        if ($url === '' || str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        return $url;
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

            $allowlist = $this->webhookAllowlist($provider);

            if (in_array('*', $allowlist, true)) {
                $violations[] = $this->allowlistKey($provider);
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * Провайдеры, у которых IP-allowlist содержит записи, не являющиеся ни
     * точным адресом, ни CIDR.
     *
     * `WebhookAuthenticator` такие записи не выбрасывает, но и не сравнивает:
     * `IpRange::contains()` на них отвечает `false`. То есть запись вида
     * `185.32.0.0/33` или `185.32.0.0 /24` не защищает ничего, а выглядит как
     * настроенный allowlist. На проде это означает 403 на всех уведомлениях
     * провайдера — и обнаруживается только по неоплаченным заказам.
     *
     * @return array<string, list<string>> Имя переменной `.env` => плохие записи.
     */
    private function malformedWebhookAllowlists(): array
    {
        $violations = [];

        foreach ((array) config('nabilet.payment.providers', []) as $provider) {
            $provider = trim((string) $provider);

            if ($provider === '') {
                continue;
            }

            $malformed = array_values(array_filter(
                $this->webhookAllowlist($provider),
                static fn (string $entry): bool => $entry !== '*' && ! IpRange::isValid($entry),
            ));

            if ($malformed !== []) {
                $violations[$this->allowlistKey($provider)] = array_values(array_unique($malformed));
            }
        }

        return $violations;
    }

    /**
     * Записи allowlist как их видит `WebhookAuthenticator`: строки без пробелов
     * по краям.
     *
     * `config/nabilet.php` режет переменную по запятой без `trim`, поэтому
     * `185.32.0.0/24, 185.32.1.0/24` даёт вторую запись с ведущим пробелом.
     * Проверка обязана судить о том же значении, что и рантайм, — иначе она
     * ругалась бы на запись, которая на самом деле работает.
     *
     * @return list<string>
     */
    private function webhookAllowlist(string $provider): array
    {
        $configured = (array) config("nabilet.payment.{$provider}.webhook_ip_allowlist", []);

        return array_values(array_map(
            static fn ($value): string => trim((string) $value),
            $configured,
        ));
    }

    /**
     * Имя переменной `.env` для allowlist провайдера.
     *
     * Ключ выводится из имени провайдера, а не зашит как `YOOKASSA_...`: при
     * добавлении второго провайдера сообщение обязано называть его переменную, а
     * не чужую.
     */
    private function allowlistKey(string $provider): string
    {
        return strtoupper($provider) . '_WEBHOOK_IP_ALLOWLIST';
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
