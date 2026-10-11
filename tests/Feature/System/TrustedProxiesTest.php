<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Http\Middleware\TrustProxies;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Доверие `X-Forwarded-*` без явного списка прокси (аудит S-08).
 *
 * ДЕФЕКТ (по отчёту). `bootstrap/app.php` конфигурирует middleware как
 * `trustProxies(at: env('TRUSTED_PROXIES') ? explode(',', …) : null)`, и в
 * `.env.example` переменная пустая. Если `null` означало бы «доверять всем»,
 * то `X-Forwarded-For` от кого угодно задавал бы IP клиента, а это ломает разом
 * две защиты: лимитер (`throttle:auth` считает 5 попыток входа НА IP — с
 * подделкой заголовка брутфорс не ограничен) и IP-allowlist платёжных вебхуков
 * (для ЮKassa он единственный слой проверки — подделать уведомление об оплате
 * стало бы тривиально).
 *
 * РЕЗУЛЬТАТ РАЗБОРА. Кода менять не нужно: `Illuminate\Http\Middleware\
 * TrustProxies::handle()` ПЕРВОЙ строкой вызывает `setTrustedProxies([])`, а
 * `null` не попадает ни в ветку `'*'`, ни в `is_array()` — то есть список так и
 * остаётся пустым. Ниже это закреплено регрессионным тестом, потому что
 * «безопасно по случайности» — не свойство, на которое можно опираться молча:
 * одна правка `at: … : '*'` вернёт дефект, и никакой другой тест его не поймает.
 *
 * ВТОРАЯ ОПОРА — ОТСУТСТВИЕ `config/trustedproxy.php`. Middleware падает на
 * `config('trustedproxy.proxies')`, когда собственный список пуст, поэтому
 * появление такого файла с `'*'` тоже открыло бы дыру. Проверяется отдельно.
 */
class TrustedProxiesTest extends TestCase
{
    private mixed $originalAlwaysTrustProxies = null;

    protected function setUp(): void
    {
        parent::setUp();

        // `TrustProxies::at()` пишет в статическое свойство, которое переживает
        // отдельный тест. Сохраняем и восстанавливаем его, чтобы контрольный
        // сценарий не влиял на остальную часть прогона.
        $property = new ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies');
        $this->originalAlwaysTrustProxies = $property->getValue();
    }

    protected function tearDown(): void
    {
        $property = new ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies');
        $property->setValue(null, $this->originalAlwaysTrustProxies);

        parent::tearDown();
    }

    /**
     * Без списка прокси заголовки `X-Forwarded-*` не влияют ни на что.
     *
     * `/up` выбран намеренно: он не требует ни БД, ни авторизации, поэтому тест
     * проверяет ровно middleware и ничего больше.
     */
    public function test_forwarded_headers_are_ignored_without_a_proxy_list(): void
    {
        $this->withHeaders([
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
        ])->get('/up')->assertOk();

        $request = $this->app['request'];

        $this->assertSame(
            [],
            $request->getTrustedProxies(),
            'пустой TRUSTED_PROXIES превратился в доверие всем прокси'
        );

        $this->assertSame(
            '127.0.0.1',
            $request->getClientIp(),
            'IP клиента взят из X-Forwarded-For, хотя прокси не объявлены'
        );

        // `X-Forwarded-Proto` намеренно НЕ проверяется через `isSecure()`:
        // тестовый клиент Laravel всегда выставляет `HTTPS=on` в server-переменных,
        // поэтому схема в тесте https независимо от заголовка, и утверждение
        // ничего бы не проверяло. IP — честный индикатор: он берётся только из
        // доверенного `X-Forwarded-For`.
    }

    /**
     * Контроль: когда прокси ОБЪЯВЛЕНЫ, тот же заголовок читается.
     *
     * Без этого утверждения предыдущий тест проходил бы и в случае, если бы
     * заголовок просто не читался никогда, — то есть ничего не доказывал бы.
     *
     * Доверенным объявляется РЕАЛЬНЫЙ `REMOTE_ADDR` тестового запроса
     * (`127.0.0.1`): `isFromTrustedProxy()` проверяет именно его, и произвольный
     * «адрес балансировщика» ничего бы не изменил.
     */
    public function test_forwarded_headers_are_honoured_when_proxies_are_declared(): void
    {
        TrustProxies::at(['127.0.0.1']);

        $this->withHeaders([
            'X-Forwarded-For' => '198.51.100.7',
        ])->get('/up')->assertOk();

        $request = $this->app['request'];

        $this->assertSame(['127.0.0.1'], $request->getTrustedProxies());
        $this->assertSame('198.51.100.7', $request->getClientIp());
    }

    /**
     * Второй источник прокси — `config/trustedproxy.proxies` — пуст.
     *
     * Middleware читает его, когда собственный список пуст, поэтому файла с
     * `'*'` достаточно, чтобы вернуть дефект, не тронув `bootstrap/app.php`.
     */
    public function test_the_fallback_proxy_config_does_not_trust_anyone(): void
    {
        $this->assertNull(
            config('trustedproxy.proxies'),
            'config/trustedproxy.php с непустым `proxies` открывает доверие X-Forwarded-*'
        );
    }
}
