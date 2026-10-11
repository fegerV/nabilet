<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Лимиты частоты на публичных эндпоинтах (аудит S-05, S-06).
 *
 * До этого `POST /promo-codes/validate` был единственным публичным POST без
 * `throttle` (у login/forgot/reset он есть — `throttle:auth`), а чекин билетов
 * не был ограничен вовсе: роль оператора закрывала чужой доступ, но не перебор
 * `ticketPublicId`.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /* ── S-05: перебор промокодов ─────────────────────────────────────────── */

    /**
     * Проверяется именно лимит, а не бизнес-ответ эндпоинта: анонимный вызов
     * сейчас падает 500 на `TenantContextMissingError` (организация для
     * публичного запроса ниоткуда не берётся) — это отдельный дефект
     * резолвинга тенанта, не имеющий отношения к S-05. Для лимита это
     * безразлично: `throttle` стоит в стеке маршрута и считает запрос
     * независимо от того, чем закончился контроллер.
     */
    public function test_promo_validation_is_rate_limited(): void
    {
        $throttled = null;

        for ($i = 0; $i < 20; $i++) {
            $response = $this->postJson('/api/v1/promo-codes/validate', ['code' => 'GUESS' . $i]);

            if ($response->status() === 429) {
                $throttled = $response;
                break;
            }
        }

        $this->assertNotNull($throttled, 'Перебор промокодов обязан упираться в лимит частоты');
        // Конверт §66 — тот же, что у `throttle:auth`: клиент ветвится по коду.
        $throttled->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    public function test_promo_limiter_adds_a_cart_bucket_only_when_a_token_is_present(): void
    {
        $limiter = RateLimiter::limiter('promo-validate');
        $this->assertNotNull($limiter);

        // Без токена корзины вторая корзина не создаётся: общий ключ
        // `promo-cart:anon` собрал бы всех анонимных покупателей в один лимит,
        // и один скрипт исчерпал бы его для всех — лимит стал бы вектором DoS.
        $this->assertCount(1, $limiter($this->request('10.0.0.1')));

        $withCart = $this->request('10.0.0.1');
        $withCart->headers->set('X-Cart-Token', 'cart-token-1');
        $this->assertCount(2, $limiter($withCart));
    }

    /* ── S-06: чекин билетов ──────────────────────────────────────────────── */

    public function test_checkin_limiter_is_keyed_by_the_operator_not_the_address(): void
    {
        $limiter = RateLimiter::limiter('checkin-scan');
        $this->assertNotNull($limiter);

        $operator = new User();
        $operator->id = 4242;

        $request = $this->request('10.0.0.1');
        $request->setUserResolver(fn () => $operator);

        $limits = $limiter($request);
        $limits = is_array($limits) ? $limits : [$limits];

        $this->assertCount(1, $limits);
        $this->assertSame(120, $limits[0]->maxAttempts);
        // Ключ — пользователь: сканер на входе ходит через меняющийся мобильный
        // IP, и привязка к адресу давала бы свежую корзину после каждой смены
        // сети, то есть лимит не работал бы вовсе.
        $this->assertSame('checkin:4242', $limits[0]->key);
    }

    public function test_the_affected_routes_carry_their_throttles(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $promo = $routes->first(fn ($r) => $r->uri() === 'api/v1/promo-codes/validate');
        $scan = $routes->first(fn ($r) => $r->uri() === 'api/v1/tickets/checkin/scan');
        $verify = $routes->first(fn ($r) => $r->uri() === 'api/v1/tickets/checkin/verify');

        $this->assertNotNull($promo);
        $this->assertNotNull($scan);
        $this->assertNotNull($verify);

        $this->assertContains('throttle:promo-validate', $promo->gatherMiddleware());
        $this->assertContains('throttle:checkin-scan', $scan->gatherMiddleware());
        $this->assertContains('throttle:checkin-scan', $verify->gatherMiddleware());
    }

    private function request(string $ip): Request
    {
        return Request::create('/api/v1/promo-codes/validate', 'POST', server: ['REMOTE_ADDR' => $ip]);
    }
}
