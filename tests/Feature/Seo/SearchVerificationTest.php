<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Nabilet\Core\Http\SearchVerification;
use Nabilet\Modules\Events\Http\EventPageShell;
use Tests\TestCase;

/**
 * Коды подтверждения прав на сайт — один владелец на три рендерера.
 *
 * Блок из двух `if`-ов стоял в `Content\Services\PageRenderer`,
 * `Seo\Services\LandingPageRenderer` и `Events\Http\EventPageShell`, и каждый
 * докблок ссылался на два других как на «ту же логику». Копия была известна —
 * и всё равно жила в трёх экземплярах, потому что правку нужно было вносить
 * трижды.
 *
 * ПУСТОЕ ЗНАЧЕНИЕ — НЕ ТЕГ. `<meta name="yandex-verification" content="">`
 * Яндекс.Вебмастер читает как ошибку подтверждения, поэтому «кода нет» обязано
 * означать «тега нет», а не «тег с пустым содержимым».
 */
class SearchVerificationTest extends TestCase
{
    public function test_no_tags_when_codes_are_not_configured(): void
    {
        config([
            'nabilet.seo.verification.yandex' => null,
            'nabilet.seo.verification.google' => null,
        ]);

        self::assertSame([], SearchVerification::tagList());
        self::assertSame('', SearchVerification::tags());
    }

    public function test_blank_code_produces_no_tag(): void
    {
        // Пробел в настройке — такая же ошибка конфигурации, как отсутствие
        // значения, и не должна превращаться в тег с пробелом внутри.
        config([
            'nabilet.seo.verification.yandex' => '   ',
            'nabilet.seo.verification.google' => '',
        ]);

        self::assertSame('', SearchVerification::tags());
    }

    public function test_both_codes_are_emitted_in_a_stable_order(): void
    {
        config([
            'nabilet.seo.verification.yandex' => 'ya-123',
            'nabilet.seo.verification.google' => 'goog-456',
        ]);

        self::assertSame(
            ['<meta name="yandex-verification" content="ya-123" />', '<meta name="google-site-verification" content="goog-456" />'],
            SearchVerification::tagList(),
        );
    }

    public function test_code_is_trimmed_and_escaped(): void
    {
        config(['nabilet.seo.verification.yandex' => '  a"b&c  ']);

        self::assertSame(
            '<meta name="yandex-verification" content="a&quot;b&amp;c" />',
            SearchVerification::tags(),
        );
    }

    /**
     * Страница события — один из трёх потребителей; проверяем, что она берёт
     * коды именно у владельца, а не собирает их сама.
     */
    public function test_event_page_emits_the_shared_tags(): void
    {
        config([
            'nabilet.seo.verification.yandex' => 'ya-123',
            'nabilet.seo.verification.google' => 'goog-456',
            'nabilet.storefront.shell_path' => base_path('tests/Fixtures/storefront-shell.html'),
        ]);

        $html = (new EventPageShell())->render([
            'title' => 'Концерт',
            'description' => 'Описание события',
            'canonical' => 'https://nabilet.test/event/concert',
            'jsonLd' => '{"@type":"Event"}',
            'robots' => 'index, follow',
            'image' => 'https://nabilet.test/poster.jpg',
            'imageAlt' => 'Афиша',
            'imageWidth' => 1200,
            'imageHeight' => 630,
            'venue' => ['city' => 'Сургут', 'region' => 'RU-KHM'],
            'eventPublicId' => '01J8Z0PUBLICID0000000000AB',
        ]);

        self::assertStringContainsString(SearchVerification::tags(), $html);
        self::assertStringContainsString('<meta name="robots" content="index, follow" />', $html);
        self::assertStringContainsString('<meta name="geo.region" content="RU-KHM" />', $html);
        self::assertStringContainsString('<meta name="geo.placename" content="Сургут" />', $html);
        self::assertStringContainsString('<meta property="og:image:width" content="1200" />', $html);
    }

    /** Событие без площадки не должно получить полу-валидный `geo.*`. */
    public function test_event_without_venue_has_no_geo_tags(): void
    {
        config([
            'nabilet.seo.verification.yandex' => null,
            'nabilet.seo.verification.google' => null,
            'nabilet.storefront.shell_path' => base_path('tests/Fixtures/storefront-shell.html'),
        ]);

        $html = (new EventPageShell())->render([
            'title' => 'Онлайн-концерт',
            'description' => 'Описание',
            'canonical' => 'https://nabilet.test/event/online',
            'jsonLd' => '{"@type":"Event"}',
        ]);

        self::assertStringNotContainsString('geo.region', $html);
        self::assertStringNotContainsString('geo.placename', $html);
        self::assertStringNotContainsString('yandex-verification', $html);
    }
}
