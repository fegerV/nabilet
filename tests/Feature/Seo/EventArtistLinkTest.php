<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Nabilet\Tests\Support\CatalogFixtures;
use Tests\TestCase;

/**
 * Перелинковка «событие → артист» (стратегия SEO, пункт 9 дорожной карты).
 *
 * ПОЧЕМУ ЭТО ОТДЕЛЬНЫЙ ФАЙЛ, А НЕ ЧАСТЬ `LandingArtistTest`. Тот файл
 * проверяет СТРАНИЦУ артиста — как она собирается из `artists` + `artist_event`.
 * Здесь проверяется ОБРАТНОЕ направление: как событие ведёт на артиста, и это
 * другой код в другом модуле (`Events`), а не `Seo`. Смешивать их значило бы
 * получить файл, падающий по двум несвязанным причинам.
 *
 * ЧТО ИМЕННО ЗАЩИЩАЕТСЯ. Страница артиста — самый «глубокий» адрес витрины: её
 * нельзя достичь навигацией по SPA (хеш-роутинг), и без ссылки со страницы
 * события единственным путём к ней остаётся `sitemap-artists.xml`. Карта лишь
 * сообщает краулеру о существовании адреса — вес по ней не перетекает. Ссылка
 * делает артиста частью обычного обхода: робот приходит на событие из выдачи и
 * уходит по ссылке на артиста. Поэтому проверка нужна на ОБОИХ входах, которыми
 * пользуется робот:
 *
 *   • серверный HTML-блок (`EventPageController`) — его видит краулер БЕЗ JS и
 *     парсер соцсетей;
 *   • публичный JSON (`EventController::showBySlug()`) — его видит SPA, а через
 *     SPA рендерят Google и Яндекс.
 *
 * ИСКЛЮЧЕНИЕ СНЯТЫХ АРТИСТОВ ПРОВЕРЯЕТСЯ НА ОБОИХ ВХОДАХ ТОЖЕ. Ссылка ведёт на
 * `/artist/{slug}`, а та страница отвечает 404 для неактивного артиста (порог
 * индексации). Ссылка на 404 со страницы события — это «URL not found» в отчёте
 * краулера и потраченный впустую бюджет обхода. Поэтому `Event::artists()`
 * фильтрует по `status = 'active'`, и обе ветки обязаны это уважать.
 */
final class EventArtistLinkTest extends TestCase
{
    use CatalogFixtures;
    use RefreshDatabase;

    private const BLOCK = 'seo-content-fallback';
    private const ARTIST_BLOCK = 'seo-fallback__artists';

    // ── Серверный HTML-блок ────────────────────────────────────────────────

    /** Событие с исполнителем отдаёт сервером ссылку на его страницу. */
    public function test_the_server_rendered_block_links_to_the_event_artist(): void
    {
        $event = $this->seedCatalogEvent('Рок-концерт', 'zal-art-link-1', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        $block = $this->contentBlock($this->page($event));

        $this->assertStringContainsString(self::ARTIST_BLOCK, $block);
        $this->assertStringContainsString(
            '<a href="' . $this->artistUrl('bi-2') . '">Би-2</a>',
            $block
        );
    }

    /** Каждый исполнитель события попадает в блок — их может быть несколько. */
    public function test_the_server_rendered_block_lists_every_artist(): void
    {
        $event = $this->seedCatalogEvent('Фестиваль', 'zal-art-link-2', 'Казань');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);
        $this->seedArtist('Ночные снайперы', 'nochnye-snaipery', [$event['event']]);

        $block = $this->contentBlock($this->page($event));

        $this->assertStringContainsString('href="' . $this->artistUrl('bi-2') . '"', $block);
        $this->assertStringContainsString('href="' . $this->artistUrl('nochnye-snaipery') . '"', $block);
    }

    /**
     * Событие БЕЗ исполнителей не печатает пустой блок.
     *
     * `<p class="seo-fallback__artists">Исполнители: </p>` без единой ссылки —
     * это та же «тонкая» разметка, от которой избавляется весь серверный слой:
     * слово без содержания, ради которого краулер тратит обход. Пустой список
     * обязан дать ПУСТУЮ строку, а не пустой абзац.
     */
    public function test_an_event_without_artists_renders_no_artist_block(): void
    {
        $event = $this->seedCatalogEvent('Спектакль', 'zal-art-link-3', 'Москва');

        $this->assertStringNotContainsString(
            self::ARTIST_BLOCK,
            $this->contentBlock($this->page($event))
        );
    }

    /** Снятый с публикации артист не оставляет ссылку, ведущую на 404. */
    public function test_an_inactive_artist_is_not_linked_from_the_event_page(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-link-4', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']], status: 'inactive');

        $this->assertStringNotContainsString(
            self::ARTIST_BLOCK,
            $this->contentBlock($this->page($event))
        );
    }

    /** Событие ссылается на СВОИХ артистов, а не на всех подряд. */
    public function test_the_event_does_not_link_to_another_events_artist(): void
    {
        $mine = $this->seedCatalogEvent('Мой концерт', 'zal-art-link-5', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$mine['event']]);

        $theirs = $this->seedCatalogEvent('Чужой концерт', 'zal-art-link-6', 'Москва');
        $this->seedArtist('Аквариум', 'akvarium', [$theirs['event']]);

        $block = $this->contentBlock($this->page($mine));

        $this->assertStringContainsString('href="' . $this->artistUrl('bi-2') . '"', $block);
        $this->assertStringNotContainsString('href="' . $this->artistUrl('akvarium') . '"', $block);
    }

    /**
     * Имя исполнителя экранируется.
     *
     * Имя приходит из админки, то есть от пользователя, и печатается в теле
     * страницы. Вставка его как разметки — stored-XSS; в проекте для
     * пользовательского текста запрещён `v-html`, и серверный блок не исключение.
     */
    public function test_the_artist_name_is_escaped_in_the_server_block(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-link-7', 'Москва');
        $this->seedArtist('Би-2 <b>«Зима»</b>', 'bi-2', [$event['event']]);

        $block = $this->contentBlock($this->page($event));

        $this->assertStringContainsString('Би-2 &lt;b&gt;«Зима»&lt;/b&gt;', $block);
        $this->assertStringNotContainsString('<b>', $block);
    }

    // ── Публичный API ──────────────────────────────────────────────────────

    /** Витрина получает исполнителей в ответе события — только имя и адрес. */
    public function test_the_public_api_exposes_artists_as_name_and_slug(): void
    {
        $event = $this->seedCatalogEvent('Рок-концерт', 'zal-art-link-8', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        $response = $this->apiEvent($event);

        $response->assertOk();
        $response->assertJsonPath('data.artists.0.name', 'Би-2');
        $response->assertJsonPath('data.artists.0.slug', 'bi-2');
    }

    /**
     * Ответ витрины — ИМЯ И АДРЕС, и ничего больше.
     *
     * Профиль артиста (биография, жанр, соцсети) живёт на `/artist/{slug}` и там
     * же индексируется. Копия профиля в ответе витрины разошлась бы с той
     * страницей при первой правке, а весила бы лишние килобайты в каждом
     * ответе события.
     */
    public function test_the_api_payload_carries_no_artist_profile(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-link-9', 'Москва');
        $this->seedArtist(
            'Би-2',
            'bi-2',
            [$event['event']],
            genre: 'рок',
            bio: 'Очень длинная биография, которой в витрине не место.',
        );

        $artists = $this->apiEvent($event)->json('data.artists.0');

        $this->assertSame(['name', 'slug'], array_keys($artists));
    }

    /** Пустой список — массив, а не `null`: витрина ждёт `artists.length`. */
    public function test_the_api_returns_an_empty_list_for_an_event_without_artists(): void
    {
        $event = $this->seedCatalogEvent('Спектакль', 'zal-art-link-10', 'Москва');

        $this->apiEvent($event)->assertJsonPath('data.artists', []);
    }

    /** Снятый артист не попадает и в ответ витрины. */
    public function test_an_inactive_artist_is_not_exposed_by_the_api(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-link-11', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']], status: 'inactive');

        $this->apiEvent($event)->assertJsonPath('data.artists', []);
    }

    // ── Порядок ────────────────────────────────────────────────────────────

    /**
     * Хедлайнер идёт первым — так же, как в программе события.
     *
     * Порядок задаёт СВЯЗЬ (`artist_event.is_headliner`), а не алфавит. Данные
     * подобраны так, что алфавит и `sort_order` дали бы ДРУГОЙ порядок: только
     * правило «хедлайнер выше» объясняет ожидаемый результат. Иначе тест
     * проходил бы и на сортировке по имени, то есть не проверял бы ничего.
     */
    public function test_the_headliner_is_listed_first(): void
    {
        $event = $this->seedCatalogEvent('Фестиваль', 'zal-art-link-12', 'Москва');

        // «Аквариум» — первый по алфавиту (А < Б) и первый по sort_order.
        $akvarium = $this->seedArtist('Аквариум', 'akvarium', [$event['event']]);
        $bi2 = $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        // «Би-2» — хедлайнер, но последний и по алфавиту, и по sort_order.
        DB::table('artist_event')
            ->where('artist_id', $bi2)
            ->where('event_id', $event['event'])
            ->update(['is_headliner' => 1, 'sort_order' => 99]);

        DB::table('artist_event')
            ->where('artist_id', $akvarium)
            ->where('event_id', $event['event'])
            ->update(['is_headliner' => 0, 'sort_order' => 0]);

        $this->apiEvent($event)->assertJsonPath('data.artists.0.slug', 'bi-2');
    }

    // ── Фикстуры и помощники ───────────────────────────────────────────────

    /** Адрес страницы артиста — та же функция, что и в приложении. */
    private function artistUrl(string $slug): string
    {
        return route('landing.artist', ['slug' => $slug]);
    }

    /** @param array{slug: string, publicId: string} $event */
    private function pageResponse(array $event): TestResponse
    {
        $response = $this->get(route('events.show', [
            'slug' => $event['slug'],
            'publicId' => $event['publicId'],
        ]));

        $response->assertStatus(200);

        return $response;
    }

    /** @param array{slug: string, publicId: string} $event */
    private function page(array $event): string
    {
        return (string) $this->pageResponse($event)->getContent();
    }

    /** @param array{slug: string} $event */
    private function apiEvent(array $event): TestResponse
    {
        return $this->getJson('/api/v1/events/by-slug/' . $event['slug']);
    }

    /**
     * Содержимое серверного контентного блока.
     *
     * Утверждения проверяются на ВЫРЕЗКЕ, а не на всей странице: оболочка
     * витрины — собранный Vite `index.html` с чужими скриптами и стилями, и
     * «в HTML нет строки X» на ней доказывает не то, что нужно.
     */
    private function contentBlock(string $html): string
    {
        $this->assertSame(
            1,
            preg_match('/<div class="' . self::BLOCK . '"[^>]*>(.*?)<\/div>/s', $html, $matches),
            'в HTML нет серверного контентного блока'
        );

        return $matches[1];
    }
}
