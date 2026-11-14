<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use Tests\TestCase;

/**
 * Белый список путей SPA-оболочки (history-роутинг).
 *
 * Витрина живёт на `createWebHistory()` (`resources/js/router/index.ts`):
 * каждый экран — настоящий URL, который сервер обязан обслужить собранной
 * оболочкой, иначе обновление страницы (F5) или прямая ссылка дают 404.
 * Список путей объявлен в `routes/web.php` ЯВНОМ перечислением, и этот тест —
 * его страховка: добавили экран в роутер, забыли строку на сервере — тест
 * падает здесь, а не у покупателя в браузере.
 *
 * Оболочка в тестах — фикстура (`tests/TestCase` задаёт
 * `nabilet.storefront.shell_path`), поэтому проверяется факт «оболочка
 * отдаётся», а не содержимое сборки.
 */
final class SpaShellRoutesTest extends TestCase
{
    /**
     * Пути белого списка. Должен ЗЕРКАЛИТЬ таблицу маршрутов SPA:
     * статические экраны витрины, кабинет, покупка и админка.
     *
     * Не входит сюда:
     *   — `/` (проверяется отдельно, маршрут старше этого списка);
     *   — `/event/{slug}/seats` (регистрируется до модуля Events, своя проверка);
     *   — `/admin/{any}` (wild-card, своя проверка);
     *   — пути с параметрами роутера (`event/:slug/:publicId?` — это
     *     серверная SEO-страница `EventPageController`, у неё свои тесты).
     *
     * @var list<string>
     */
    private const SPA_PATHS = [
        'checkout',
        'checkout/demo-pay',
        'payment/success',
        'tickets',
        'login',
        'register',
        'auth/callback',
        'forgot-password',
        'reset-password',
        'account',
        'favorites',
        'subscriptions',
        'bonus',
        'club',
        'my-raffles',
        'raffles/RAFFLE123',
        'r/WELCOME1',
        'admin/login',
    ];

    /** Корень отдаёт оболочку витрины, как и прежде. */
    public function test_the_root_serves_the_storefront_shell(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        self::assertStringContainsString('<div id="app">', (string) $response->getContent());
    }

    /** Каждый путь белого списка обслуживается оболочкой, а не 404. */
    public function test_every_whitelisted_path_serves_the_spa_shell(): void
    {
        foreach (self::SPA_PATHS as $path) {
            $response = $this->get('/' . $path);

            self::assertSame(
                200,
                $response->getStatusCode(),
                "Путь «/{$path}» обязан отдавать SPA-оболочку: экран есть в роутере, но сервер не знает адрес — F5 даёт 404."
            );
            self::assertStringContainsString('<div id="app">', (string) $response->getContent());
        }
    }

    /** Статический Content-Type HTML на оболочке — не JSON-конверт. */
    public function test_the_shell_is_served_as_html(): void
    {
        $this->get('/tickets')->assertHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** Старый экран выбора мест отдаётся оболочкой ДО канонизатора Events: `?session=` выживает. */
    public function test_the_legacy_seats_route_keeps_the_session_query(): void
    {
        $response = $this->get('/event/some-slug/seats?session=42');

        $response->assertOk();
        self::assertStringContainsString('<div id="app">', (string) $response->getContent());
    }

    /** `/admin/…` ниже корня — зона SPA: оболочка отдаётся на любой раздел. */
    public function test_unknown_admin_sections_serve_the_spa_shell(): void
    {
        $this->get('/admin/orders')->assertOk();
        $this->get('/admin/halls/PLA1111111111111111111111/editor')->assertOk();
    }

    /** Неизвестный адрес НЕ обслуживается оболочкой — это брендированная 404. */
    public function test_an_unknown_path_still_returns_the_branded_404(): void
    {
        $this->get('/definitely-not-a-screen')->assertNotFound();
    }

    /** API не затронут белым списком: JSON-конверт ошибки сохраняется. */
    public function test_the_api_is_not_captured_by_the_shell(): void
    {
        $this->getJson('/api/v1/definitely-not-an-endpoint')->assertNotFound();
    }
}
