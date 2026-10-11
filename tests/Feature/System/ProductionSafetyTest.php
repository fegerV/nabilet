<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Nabilet\Modules\System\Providers\ProductionSafetyProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Production-сторож (аудит S-10).
 *
 * ДЕФЕКТ. `throttle:auth` — 5 попыток входа в минуту — считает попытки в
 * `cache.default`. По умолчанию это `database`, то есть ТА ЖЕ MySQL, которая
 * обслуживает каталог, корзину и заказы. Значит, брутфорс паролей становится
 * нагрузкой на бизнес-транзакции, а под нагрузкой лимитер деградирует вместе с
 * БД — то есть защита слабеет ровно тогда, когда нужна. Сторож проверял
 * `APP_DEBUG`, `PAYMENT_DEMO_MODE` и `TRUSTED_PROXIES`, но про хранилище
 * счётчиков молчал.
 *
 * ЧТО ПРОВЕРЯЕТСЯ. Сторож обязан ЗАМЕТИТЬ `CACHE_STORE=database` в
 * `production` и позволить осознанный opt-out (`NABILET_ALLOW_DATABASE_CACHE=true`)
 * для хостингов без Redis — иначе проверка просто ломает задокументированный
 * сценарий развёртывания.
 *
 * Проверка читает `config('cache.default')`, а не `env('CACHE_STORE')`: именно
 * это значение выбирает стор, которым пользуется `RateLimiter`. Поэтому тест
 * задаёт config, а не переменную окружения.
 */
class ProductionSafetyTest extends TestCase
{
    private string $originalEnv = 'testing';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnv = (string) $this->app['env'];

        // Остальные три проверки сторожа должны молчать: иначе тест на
        // хранилище кэша падал бы по чужой причине и ничего не доказывал.
        config([
            'app.debug' => false,
            'nabilet.payment.demo_mode' => false,
            'nabilet.payment.providers' => [],
        ]);
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->originalEnv;

        unset($_ENV['NABILET_ALLOW_DATABASE_CACHE'], $_SERVER['NABILET_ALLOW_DATABASE_CACHE']);

        parent::tearDown();
    }

    /** В production `CACHE_STORE=database` — отказ старта, а не тихая деградация. */
    public function test_the_database_cache_store_stops_production_boot(): void
    {
        $this->app['env'] = 'production';
        config(['cache.default' => 'database']);

        try {
            (new ProductionSafetyProvider($this->app))->boot();

            $this->fail('CACHE_STORE=database был принят в production');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CACHE_STORE=database', $e->getMessage());
            $this->assertStringContainsString('NABILET_ALLOW_DATABASE_CACHE=true', $e->getMessage());
        }
    }

    /**
     * Осознанный выбор хостинга без Redis снимает отказ.
     *
     * Проверка fail-closed по умолчанию, но не догматична: оператор, у которого
     * Redis физически нет, обязан иметь возможность записать это решение в `.env`
     * и запуститься.
     */
    public function test_the_database_cache_can_be_acknowledged_explicitly(): void
    {
        $this->app['env'] = 'production';
        config(['cache.default' => 'database']);

        $_ENV['NABILET_ALLOW_DATABASE_CACHE'] = 'true';
        $_SERVER['NABILET_ALLOW_DATABASE_CACHE'] = 'true';

        // Отказ не брошен: если бы opt-out не работал, здесь был бы RuntimeException.
        (new ProductionSafetyProvider($this->app))->boot();

        $this->addToAssertionCount(1);
    }

    /** Файловый/Redis-стор — норма, сторож молчит. */
    public function test_a_non_database_cache_store_is_accepted(): void
    {
        $this->app['env'] = 'production';
        config(['cache.default' => 'redis']);

        (new ProductionSafetyProvider($this->app))->boot();

        $this->addToAssertionCount(1);
    }

    /** Вне production сторож не вмешивается — локальная разработка не затронута. */
    public function test_the_guard_does_not_run_outside_production(): void
    {
        $this->app['env'] = 'local';
        config(['cache.default' => 'database', 'app.debug' => true]);

        (new ProductionSafetyProvider($this->app))->boot();

        $this->addToAssertionCount(1);
    }

    /**
     * Сообщение объясняет ПОСЛЕДСТВИЕ, а не только настройку.
     *
     * Оператор, читающий «CACHE_STORE=database», не обязан знать, что счётчики
     * `throttle:auth` живут в том же сторе. Сторож обязан сказать это сам —
     * иначе отказ выглядит как вкусовщина и его отключат вместо починки.
     */
    public function test_the_violation_explains_the_consequence(): void
    {
        $this->app['env'] = 'production';
        config(['cache.default' => 'database']);

        try {
            (new ProductionSafetyProvider($this->app))->boot();

            $this->fail('CACHE_STORE=database был принят в production');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('throttle:auth', $e->getMessage());
            $this->assertStringContainsString('rate-limit', $e->getMessage());
        }
    }

    /**
     * `PAYMENT_DEMO_MODE=true` в production — отказ старта (аудит R-13).
     *
     * `POST /payments/demo-pay` анонимен и подтверждает оплату. Единственной
     * защитой был флаг из `.env`, а `.env.example` исторически содержит
     * `PAYMENT_DEMO_MODE=true` — то есть деплой по инструкции «скопируйте
     * пример» включал бы симулятор на проде и позволял подтвердить любой заказ
     * одним запросом, без карты и без ключей ЮKassa.
     *
     * Проверка идёт через `config()`, а не `env()`: именно это значение читает
     * `PaymentController::demoPay()`, поэтому сторож обязан смотреть на то же.
     */
    public function test_the_demo_payment_mode_stops_production_boot(): void
    {
        $this->app['env'] = 'production';
        config(['cache.default' => 'redis', 'nabilet.payment.demo_mode' => true]);

        try {
            (new ProductionSafetyProvider($this->app))->boot();

            $this->fail('PAYMENT_DEMO_MODE=true был принят в production');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('PAYMENT_DEMO_MODE=true', $e->getMessage());
            $this->assertStringContainsString('/payments/demo-pay', $e->getMessage());
        }
    }
}
