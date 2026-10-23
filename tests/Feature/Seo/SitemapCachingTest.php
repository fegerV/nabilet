<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Кеширование и состав карты сайта (SEO-аудит P-10, P-14).
 *
 * ДЕФЕКТ 1 — КЕША НЕ БЫЛО. Константа `CACHE_TTL` жила в `SitemapService` с самого
 * начала и не использовалась НИ РАЗУ: каждый запрос краулера заново считал
 * `COUNT(*)` по `events`, выбирал страницу событий и собирал XML. Карта сайта —
 * документ, статический по смыслу, но поисковики обходят её регулярно и не по
 * одному разу, поэтому эндпоинт превращался в постоянную нагрузку на базу.
 * Заголовок `Cache-Control` этот случай не закрывает: он ничего не значит ни для
 * второго краулера, ни для запроса с `no-cache`, ни для ручной проверки в
 * Search Console.
 *
 * ДЕФЕКТ 2 — ВЫБОРКА НЕ СОДЕРЖАЛА ЧИТАЕМЫХ ПОЛЕЙ. Запрос брал
 * `public_id, slug, updated_at, published_at`, а сборщик XML читает ещё `status`
 * и `canonical_url`. Оба поля всегда были `null`, и обе ветки молча уходили в
 * fallback: `canonical_url` из БД в карту не попадал никогда (при том что на
 * странице применялся), а приоритет считался как для незавершённого события.
 * Ошибка не проявлялась ничем — ни исключением, ни пустым ответом.
 *
 * ЧТО ПРОВЕРЯЕТСЯ. Второй запрос обязан отдать результат из кеша — то есть НЕ
 * увидеть событие, добавленное между запросами. Это единственное наблюдаемое
 * доказательство: по времени отклика такой тест был бы флаки, а по заголовку —
 * не доказывал бы ничего, потому что `Cache-Control` отдаётся независимо от
 * наличия кеша.
 */
final class SitemapCachingTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // Каждый тест начинает с пустого кеша: иначе результат зависел бы от
        // порядка выполнения тестов.
        Cache::flush();
    }

    /** Карта-индекс отдаётся из кеша. */
    public function test_the_sitemap_index_is_served_from_cache(): void
    {
        $first = (string) $this->get('/sitemap.xml')->getContent();

        $this->assertTrue(Cache::has('seo:sitemap:index'), 'карта-индекс не попала в кеш');

        // Второй ответ отдаётся из кеша без пересборки, поэтому он побайтово
        // совпадает с первым. Сравнивать два HTTP-ответа ПОСЛЕ сброса кеша
        // нельзя: `lastmod` в индексе — это `now()` в момент сборки, и тест стал
        // бы зависимым от того, успела ли пройти секунда.
        $this->assertSame($first, (string) Cache::get('seo:sitemap:index'));
    }

    /**
     * Карта событий берётся из кеша: новое событие не появляется до сброса.
     *
     * Это и есть наблюдаемое доказательство кеша. Проверяется по slug, а не по
     * названию: карта содержит АДРЕСА, и заголовок события в неё не попадает.
     */
    public function test_the_events_sitemap_is_served_from_cache(): void
    {
        [, , $firstSlug] = $this->seedPublishedEvent('Событие до прогрева');

        $first = (string) $this->get('/sitemap-events.xml')->getContent();
        $this->assertStringContainsString($firstSlug, $first);

        [, , $secondSlug] = $this->seedPublishedEvent('Событие после прогрева');

        $cached = (string) $this->get('/sitemap-events.xml')->getContent();
        $this->assertStringNotContainsString(
            $secondSlug,
            $cached,
            'карта событий пересобралась на каждый запрос — кеш не работает'
        );

        Cache::flush();

        $fresh = (string) $this->get('/sitemap-events.xml')->getContent();
        $this->assertStringContainsString(
            $secondSlug,
            $fresh,
            'после сброса кеша карта обязана пересобраться'
        );
    }

    /** Статическая карта кешируется так же. */
    public function test_the_static_sitemap_is_served_from_cache(): void
    {
        $this->get('/sitemap-static.xml')->assertStatus(200);

        $this->assertTrue(Cache::has('seo:sitemap:static'), 'статическая карта не попала в кеш');

        Cache::flush();

        $this->assertFalse(Cache::has('seo:sitemap:static'));
    }

    /** Кеш заполняется всеми точками входа, включая посадочные карты (P-12). */
    public function test_all_sitemap_entries_populate_the_cache(): void
    {
        $this->get('/sitemap.xml');
        $this->get('/sitemap-events.xml');
        $this->get('/sitemap-static.xml');
        $this->get('/sitemap-venues.xml');
        $this->get('/sitemap-cities.xml');
        $this->get('/sitemap-categories.xml');
        $this->get('/sitemap-organizers.xml');

        $this->assertTrue(Cache::has('seo:sitemap:index'));
        $this->assertTrue(Cache::has('seo:sitemap:events:1'));
        $this->assertTrue(Cache::has('seo:sitemap:static'));
        $this->assertTrue(Cache::has('seo:sitemap:venues'));
        $this->assertTrue(Cache::has('seo:sitemap:cities'));
        $this->assertTrue(Cache::has('seo:sitemap:categories'));
        $this->assertTrue(Cache::has('seo:sitemap:organizers'));
    }

    // ── Состав карты ───────────────────────────────────────────────────────

    /** Карта содержит канонический адрес события. */
    public function test_the_sitemap_carries_the_event_canonical_url(): void
    {
        [, $eventId] = $this->seedPublishedEvent('Событие для карты');

        $row = DB::table('events')->where('id', $eventId)->first();

        $xml = (string) $this->get('/sitemap-events.xml')->getContent();

        $this->assertStringContainsString(htmlspecialchars((string) $row->slug, ENT_QUOTES | ENT_XML1), $xml);
        $this->assertStringContainsString((string) $row->public_id, $xml);
    }

    /**
     * `canonical_url` из БД реально применяется в карте (аудит P-14).
     *
     * Раньше колонка не входила в выборку, поэтому поле всегда было `null` и
     * адрес вычислялся из slug. Администратор менял canonical и не видел
     * эффекта именно в карте сайта — там, где расхождение с `<link rel="canonical">`
     * и приводит к «alternate page with proper canonical tag».
     *
     * Хост ЗДЕСЬ НАШ (`APP_URL` из `.env`). Карта принимает сохранённый адрес
     * только со своего домена — проверка общего правила живёт в
     * `EventCanonicalUrlTest`, и там же проверяется, что чужой хост в `<loc>`
     * не попадает.
     */
    public function test_a_stored_canonical_url_is_used_in_the_sitemap(): void
    {
        [, $eventId] = $this->seedPublishedEvent('Событие с canonical');

        DB::table('events')->where('id', $eventId)->update([
            'canonical_url' => 'https://tickets.example.com/event/kanonicheskij-adres/01HZZ',
        ]);

        $xml = (string) $this->get('/sitemap-events.xml')->getContent();

        $this->assertStringContainsString('kanonicheskij-adres', $xml);
    }

    /**
     * Опубликованное событие получает повышенный приоритет (0.9, не 0.8).
     *
     * Тоже следствие отсутствующей колонки: без `status` в выборке условие
     * `$event->status === EventStatus::PUBLISHED` было всегда ложно, и ветка для
     * опубликованных событий не выполнялась ни разу.
     */
    public function test_a_published_event_gets_the_high_priority(): void
    {
        $this->seedPublishedEvent('Приоритетное событие');

        $xml = (string) $this->get('/sitemap-events.xml')->getContent();

        $this->assertStringContainsString('<priority>0.9</priority>', $xml);
        $this->assertStringNotContainsString('<priority>0.8</priority>', $xml);
    }

    /** Завершённое событие остаётся в карте, но с обычным приоритетом. */
    public function test_a_completed_event_keeps_the_default_priority(): void
    {
        [, $eventId] = $this->seedPublishedEvent('Завершённое событие');

        DB::table('events')->where('id', $eventId)->update(['status' => 'completed']);

        $xml = (string) $this->get('/sitemap-events.xml')->getContent();

        $this->assertStringContainsString('<priority>0.8</priority>', $xml);
    }

    /**
     * Опубликованное событие, попадающее в карту.
     *
     * `getEventsSitemap()` требует заполненный `published_at` не в будущем —
     * `seedSellableSeat()` его не ставит, поэтому он проставляется здесь.
     *
     * @return array{0: int, 1: int, 2: string} [organizationId, eventId, slug]
     */
    private function seedPublishedEvent(string $title): array
    {
        [$organizationId, $eventId] = $this->seedSellableSeat(available: 3, eventTitle: $title);

        $slug = 'event-' . Str::random(8);

        DB::table('events')->where('id', $eventId)->update([
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'slug' => $slug,
        ]);

        return [$organizationId, $eventId, $slug];
    }
}
