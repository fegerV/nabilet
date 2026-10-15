<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Nabilet\Modules\System\Providers\ProductionSafetyProvider;
use Nabilet\Modules\System\Support\ProductionSafetyAudit;
use RuntimeException;
use Tests\TestCase;

/**
 * Гейт деплоя `config:validate` (ТЗ §79, P0-7).
 *
 * ДЕФЕКТ. Проверка небезопасных настроек существовала ровно в одном виде —
 * исключение на `boot()` провайдера, и только при `APP_ENV=production`. Слепое
 * пятно у этого механизма дорогое: на стенде, где `APP_ENV` ещё не `production`,
 * он молчит, поэтому небезопасная конфигурация обнаруживалась не до деплоя, а в
 * момент переключения трафика — когда приложение уже отказывается стартовать.
 * Критерий приёмки P0-7 требует именно автотест `config:validate`, который
 * падает при любом из нарушений.
 *
 * ЧТО ПРОВЕРЯЕТСЯ. Команда зарегистрирована, возвращает ненулевой код при каждом
 * нарушении (это делает её пригодной для скрипта деплоя) и нулевой на безопасной
 * конфигурации. Отдельно проверяется, что правила у команды и у провайдера ОДНИ:
 * копия правил разошлась бы молча — так в этом проекте разошлись три копии DDL.
 *
 * `TRUSTED_PROXIES` задаётся через `$_ENV`: сторож читает его через `env()`, как
 * и `bootstrap/app.php`. Без этого результат зависел бы от `.env` разработчика.
 */
final class ConfigValidateTest extends TestCase
{
    /** @var array{0: mixed, 1: mixed} */
    private array $originalTrustedProxies = [null, null];

    private string $originalEnv = 'testing';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnv = (string) $this->app['env'];
        $this->originalTrustedProxies = [$_ENV['TRUSTED_PROXIES'] ?? null, $_SERVER['TRUSTED_PROXIES'] ?? null];

        // Безопасная конфигурация: каждый тест меняет ровно одно значение и
        // падает по своей причине, а не по чужой.
        config([
            'app.debug' => false,
            'app.url' => 'https://nabilet.test',
            'nabilet.payment.demo_mode' => false,
            'nabilet.payment.providers' => ['yookassa'],
            'nabilet.payment.yookassa.webhook_ip_allowlist' => ['185.71.76.0/27'],
            'cache.default' => 'redis',
        ]);

        $_ENV['TRUSTED_PROXIES'] = '';
        $_SERVER['TRUSTED_PROXIES'] = '';
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->originalEnv;

        foreach (['TRUSTED_PROXIES', 'NABILET_ALLOW_DATABASE_CACHE'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }

        if ($this->originalTrustedProxies[0] !== null) {
            $_ENV['TRUSTED_PROXIES'] = $this->originalTrustedProxies[0];
        }

        if ($this->originalTrustedProxies[1] !== null) {
            $_SERVER['TRUSTED_PROXIES'] = $this->originalTrustedProxies[1];
        }

        parent::tearDown();
    }

    /** Команда зарегистрирована и на безопасной конфигурации завершается нулём. */
    public function test_a_safe_configuration_passes(): void
    {
        $this->artisan('config:validate')
            ->expectsOutputToContain('Конфигурация безопасна для продакшена.')
            ->assertExitCode(0);
    }

    /** `APP_DEBUG=true` — первое, что обязан ловить гейт: страницы ошибок текут. */
    public function test_app_debug_is_rejected(): void
    {
        config(['app.debug' => true]);

        $this->artisan('config:validate')
            ->expectsOutputToContain('APP_DEBUG=true')
            ->assertExitCode(1);
    }

    /** `PAYMENT_DEMO_MODE=true` подтверждает оплату анонимным запросом. */
    public function test_the_demo_payment_mode_is_rejected(): void
    {
        config(['nabilet.payment.demo_mode' => true]);

        $this->artisan('config:validate')
            ->expectsOutputToContain('PAYMENT_DEMO_MODE=true')
            ->assertExitCode(1);
    }

    /** Звёздочка в allowlist вебхуков = «подтвердить оплату может кто угодно». */
    public function test_a_wildcard_webhook_allowlist_is_rejected(): void
    {
        config(['nabilet.payment.yookassa.webhook_ip_allowlist' => ['*']]);

        $this->artisan('config:validate')
            ->expectsOutputToContain('YOOKASSA_WEBHOOK_IP_ALLOWLIST=*')
            ->assertExitCode(1);
    }

    /** `TRUSTED_PROXIES=*` обнуляет и rate-limit, и IP-allowlist вебхуков. */
    public function test_a_wildcard_trusted_proxies_is_rejected(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '*';
        $_SERVER['TRUSTED_PROXIES'] = '*';

        $this->artisan('config:validate')
            ->expectsOutputToContain('TRUSTED_PROXIES=*')
            ->assertExitCode(1);
    }

    /** Схема `APP_URL` уезжает в canonical, og:url и карту сайта. */
    public function test_an_http_app_url_is_rejected(): void
    {
        config(['app.url' => 'http://nabilet.test']);

        $this->artisan('config:validate')
            ->expectsOutputToContain('APP_URL=http://nabilet.test')
            ->assertExitCode(1);
    }

    /** Кэш в той же БД, что и заказы, — деградация лимитера под нагрузкой. */
    public function test_the_database_cache_is_rejected(): void
    {
        config(['cache.default' => 'database']);

        $this->artisan('config:validate')
            ->expectsOutputToContain('CACHE_STORE=database')
            ->assertExitCode(1);
    }

    /**
     * Провайдер бросает ровно тот набор правил, который печатает команда.
     *
     * Это и есть проверка «владелец правил один». Если кто-нибудь вернёт правило
     * внутрь провайдера (или наоборот, добавит его только в команду), наборы
     * разъедутся: провайдер перестанет пускать приложение на стенде, а команда
     * на том же конфиге будет зелёной — то есть гейт начнёт врать.
     */
    public function test_the_provider_and_the_command_share_one_set_of_rules(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'cache.default' => 'database']);

        try {
            (new ProductionSafetyProvider($this->app))->boot();

            $this->fail('сторож не заметил небезопасную конфигурацию');
        } catch (RuntimeException $e) {
            $violations = $this->app->make(ProductionSafetyAudit::class)->violations();

            $this->assertNotSame([], $violations);

            foreach ($violations as $violation) {
                $this->assertStringContainsString($violation, $e->getMessage());
            }

            // Ровно столько пунктов, сколько правил: лишний означал бы, что
            // правило живёт ещё и в провайдере.
            $this->assertSame(
                count($violations),
                substr_count($e->getMessage(), "\n  - "),
                'провайдер и команда разошлись по числу правил'
            );
        }
    }
}
