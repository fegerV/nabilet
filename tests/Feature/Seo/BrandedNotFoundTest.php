<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nabilet\Core\Http\BrandPalette;
use Tests\TestCase;

/**
 * Брендированная страница 404 для несуществующих путей (SEO-аудит P-16).
 *
 * ДЕФЕКТ. «Страницы 404» не существовало как документа. Произвольный путь
 * (`/ljkbdf`) доходил до Laravel и получал ответ фреймворка, а
 * `/event/{несуществующий}` — JSON-конверт §66. Статус был верным в обоих
 * случаях, тело — нет:
 *
 *   • JSON на человекочитаемом URL. `/event/…` человек открывает из поисковой
 *     выдачи или из письма; `{"error":{"code":…}}` в браузере читается как
 *     «сайт сломан», а не как «страницы больше нет»;
 *   • ноль навигации. Пришедший по устаревшей ссылке уходил в тупик: ни одной
 *     ссылки, тогда как единственное полезное действие — вернуться в афишу.
 *     Для поисковика это тоже потеря: `follow` по ссылке передаёт вес каталогу,
 *     а тупик — нет.
 *
 * ЧТО ПРОВЕРЯЕТСЯ. Страница обязана: отвечать 404 (а не 200 — иначе это soft
 * 404), быть HTML, содержать ссылку на каталог, закрываться от индекса и НЕ
 * зависеть от собранной витрины (иначе сломанная сборка превращает «страницы
 * нет» в «сервер недоступен»).
 *
 * ОТДЕЛЬНО: catch-all не должен съесть API. `Route::fallback()` вместо маски
 * `{any}` выбран именно поэтому — `withRouting()` регистрирует web-маршруты
 * раньше api, и маска перехватила бы `/api/v1/...` целиком.
 */
final class BrandedNotFoundTest extends TestCase
{
    use RefreshDatabase;

    /** Неизвестный путь отдаёт HTML-страницу со статусом 404. */
    public function test_an_unknown_path_returns_a_branded_html_page(): void
    {
        $response = $this->get('/ljkbdf-' . uniqid());

        $response->assertStatus(404);

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));

        $body = (string) $response->getContent();

        $this->assertStringContainsString('Страница не найдена', $body);
        // Ссылка в каталог — ради неё страница и существует.
        $this->assertStringContainsString('href="/"', $body);
        $this->assertStringContainsString('Перейти к афише', $body);
    }

    /**
     * Страница закрыта от индекса, но ссылки по ней идут.
     *
     * `noindex` — чтобы брендированное тело не попало в индекс «тонкой
     * страницей»; `follow` — чтобы вес уходил в каталог.
     */
    public function test_the_page_is_noindex_but_follow(): void
    {
        $this->get('/net-takoj-stranicy')
            ->assertSee('<meta name="robots" content="noindex, follow" />', false);
    }

    /**
     * Запрошенный путь показывается, но ЭКРАНИРОВАННЫМ.
     *
     * Путь приходит из URL и может содержать что угодно. Это классическая
     * reflected-XSS через страницу ошибки: её показывают ровно тем, кто
     * перебирает адреса.
     *
     * Две проверки, потому что они ловят разные вещи:
     *   — интеграционная: процентное кодирование (`%3C`) НЕ раскодируется в
     *     `<` — иначе экранирование было бы единственной защитой;
     *   — прямая: если `<` в пути окажется (некоторые клиенты шлют его как есть),
     *     рендер обязан его заменить.
     */
    public function test_the_requested_path_is_escaped(): void
    {
        $body = (string) $this->get('/xss-%3Cscript%3Ealert(1)%3C%2Fscript%3E')->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);

        $direct = (string) \Nabilet\Core\Http\NotFoundPage::response('/<script>alert(1)</script>')->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $direct);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $direct);
    }

    /**
     * Страница работает и без собранной витрины.
     *
     * Проверка не теоретическая: `dist/` — артефакт сборки, и его отсутствие
     * (свежий клон, неудачный деплой) не должно превращать «страница не
     * найдена» в «сервер недоступен». Поэтому у документа нет ни одного
     * внешнего ресурса — ни шрифтов, ни скриптов, ни картинок.
     */
    public function test_the_page_does_not_depend_on_the_storefront_build(): void
    {
        config(['nabilet.storefront.shell_path' => base_path('dist/does-not-exist.html')]);

        $response = $this->get('/vse-eshche-net-takoj-stranicy');

        $response->assertStatus(404);

        $body = (string) $response->getContent();

        $this->assertStringContainsString('Страница не найдена', $body);
        $this->assertStringNotContainsString('<script src=', $body);
        $this->assertStringNotContainsString('<link rel="stylesheet"', $body);
    }

    /**
     * Catch-all НЕ перехватывает API.
     *
     * Это главный риск реализации: `withRouting()` регистрирует web-маршруты
     * раньше api, поэтому маска `{any}` в `routes/web.php` отдавала бы HTML
     * вместо JSON на КАЖДЫЙ запрос к API. `Route::fallback()` проверяется
     * роутером только после всех обычных маршрутов — этот тест фиксирует, что
     * так оно и есть.
     */
    public function test_the_fallback_does_not_swallow_the_api(): void
    {
        $this->getJson('/api/v1/events')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json');
    }

    /**
     * Неизвестный путь внутри API отдаёт JSON, а не HTML.
     *
     * `/api/v1/net-takogo` не совпадает ни с одним api-маршрутом, поэтому его
     * ловит fallback — но ответ обязан быть JSON: клиент API разбирает ответ, а
     * HTML-страница в ответе на `Accept: application/json` — это ошибка
     * контракта, которую заметит не каждый клиент.
     */
    public function test_an_unknown_api_path_returns_json(): void
    {
        $response = $this->getJson('/api/v1/net-takogo-endpointa-' . uniqid());

        $response->assertStatus(404);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    /**
     * Служебные страницы не задеты.
     *
     * `/up` (health-check) и `/assets/...` — реальные маршруты, и fallback
     * обязан их пропускать: если бы он их перехватывал, мониторинг видел бы 404
     * на живом сервисе.
     */
    public function test_real_routes_are_not_shadowed(): void
    {
        $this->get('/up')->assertStatus(200);
    }

    /**
     * Палитра бренда — ОДИН владелец, а не копия на страницу.
     *
     * Раньше `NotFoundPage` держал собственные `COLOR_*` константы с теми же
     * шестью HEX-значениями, что и `BrandPalette`, — то есть был ровно той
     * «второй копией», от которой предостерегает докблок палитры. Правка бренда
     * в одном месте оставила бы 404 на старом цвете, и заметить это можно было
     * бы только глазами.
     *
     * Две проверки:
     *   — выходной HTML содержит значения ИМЕННО из `BrandPalette` (страница
     *     берёт их оттуда, а не из своей таблицы);
     *   — в исходнике класса нет собственных цветовых констант (нельзя вернуть
     *     копию, не уронив тест).
     */
    public function test_the_page_uses_the_shared_brand_palette_not_a_copy(): void
    {
        $body = (string) \Nabilet\Core\Http\NotFoundPage::response('/x')->getContent();

        foreach ([
            BrandPalette::CANVAS,
            BrandPalette::SURFACE,
            BrandPalette::CONTENT,
            BrandPalette::MUTED,
            BrandPalette::BRAND,
            BrandPalette::ACCENT,
        ] as $color) {
            $this->assertStringContainsString($color, $body, sprintf('палитра: %s', $color));
        }

        $source = (string) file_get_contents(base_path('app/Core/Http/NotFoundPage.php'));

        $this->assertStringNotContainsString('const COLOR_', $source);
    }
}
