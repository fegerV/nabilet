<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Events\StateMachines\EventStateMachine;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * `lastmod` и `changefreq` карты сайта (SEO-аудит P-22).
 *
 * ДЕФЕКТ. `lastmod` записи брался как
 * `($event->updated_at ?? $event->published_at) ?? now()` — без ограничений.
 * Отсюда два следствия, каждое из которых обесценивает поле целиком:
 *
 *   • значение могло оказаться В БУДУЩЕМ (расхождение часов, строка с будущей
 *     датой). Google на такое не делает скидку — он перестаёт доверять
 *     `lastmod` во ВСЁМ файле, а не в одной записи;
 *   • значение могло оказаться РАНЬШЕ `published_at`, то есть карта
 *     утверждала, что страница не менялась до того, как появилась.
 *
 * Отдельно: `changefreq` был захардкожен `weekly` для всего, включая
 * завершённые события, страницы которых не изменятся больше никогда, и
 * `lastmod` в КАРТЕ-ИНДЕКСЕ подставлялся как `now()` — то есть каждая дочерняя
 * карта «изменялась только что» при каждом обходе, хотя XML между сборками
 * побайтово одинаков. Постоянно двигающееся значение спецификация sitemap.org
 * (`<lastmod>` в индексе — «когда изменился соответствующий файл») и практика
 * Google одинаково считают недостоверным.
 */
final class SitemapLastmodTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    // ── lastmod записи ─────────────────────────────────────────────────────

    /** Обычный случай: `lastmod` — это момент последней правки записи. */
    public function test_lastmod_is_the_row_update_moment(): void
    {
        $event = $this->seedEvent();
        $updatedAt = Carbon::parse('2026-03-01 10:00:00');

        $this->touchEvent($event, ['updated_at' => $updatedAt, 'published_at' => Carbon::parse('2026-01-01 00:00:00')]);

        $this->assertSame($updatedAt->toIso8601String(), $this->eventLastmod($event['slug']));
    }

    /**
     * Будущая дата не уезжает в карту.
     *
     * Это не косметика: `lastmod` в будущем Google трактует как недостоверный и
     * перестаёт учитывать поле для всего файла.
     */
    public function test_a_future_update_moment_is_clamped_to_now(): void
    {
        $event = $this->seedEvent();

        $this->touchEvent($event, [
            'updated_at' => now()->addDays(30),
            'published_at' => now()->subMonth(),
        ]);

        $lastmod = Carbon::parse((string) $this->eventLastmod($event['slug']));

        $this->assertFalse(
            $lastmod->isFuture(),
            'lastmod в будущем обесценивает поле для всего файла карты'
        );
    }

    /**
     * `lastmod` не может быть раньше публикации.
     *
     * Публикация — тоже изменение страницы, и карта, сообщающая дату до
     * появления страницы, противоречит сама себе.
     */
    public function test_lastmod_is_never_earlier_than_publication(): void
    {
        $event = $this->seedEvent();
        $publishedAt = Carbon::parse('2026-05-01 00:00:00');

        $this->touchEvent($event, [
            'updated_at' => Carbon::parse('2026-01-01 00:00:00'),
            'published_at' => $publishedAt,
        ]);

        $this->assertSame($publishedAt->toIso8601String(), $this->eventLastmod($event['slug']));
    }

    // ── changefreq ─────────────────────────────────────────────────────────

    /**
     * Завершённое событие — архив: его страница не изменится больше никогда.
     *
     * `weekly` для неё был заведомой неправдой, а частоту обхода читают Яндекс и
     * Bing (Google её игнорирует), то есть краулинговый бюджет тратился на
     * страницу, которая не меняется.
     */
    public function test_a_completed_event_is_marked_as_a_rarely_changing_archive(): void
    {
        $event = $this->seedEvent(EventStateMachine::COMPLETED);

        $this->assertStringContainsString(
            '<changefreq>yearly</changefreq>',
            $this->eventsSitemapXml()
        );

        $this->assertSame(EventStateMachine::COMPLETED, $this->statusOf($event));
    }

    /** У опубликованного события частота остаётся недельной. */
    public function test_a_published_event_keeps_the_weekly_frequency(): void
    {
        $this->seedEvent(EventStateMachine::PUBLISHED);

        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $this->eventsSitemapXml());
    }

    // ── lastmod карты-индекса ──────────────────────────────────────────────

    /**
     * `lastmod` дочерней карты — момент изменения содержимого, а не `now()`.
     *
     * Проверяется точным сравнением с `updated_at` события: если бы в индексе
     * стоял текущий момент, значения совпали бы только случайно (тест
     * фиксирует `updated_at` в прошлом).
     */
    public function test_the_index_lastmod_reflects_content_change_not_the_response_moment(): void
    {
        $event = $this->seedEvent();
        $updatedAt = Carbon::parse('2026-04-02 09:30:00');

        $this->touchEvent($event, [
            'updated_at' => $updatedAt,
            'published_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        $xml = $this->indexXml();

        $this->assertStringContainsString($updatedAt->toIso8601String(), $xml);
        $this->assertStringNotContainsString(
            now()->toIso8601String(),
            $xml,
            'карта-индекс сообщает момент ответа вместо момента изменения содержимого'
        );
    }

    // ── Фикстуры ───────────────────────────────────────────────────────────

    /**
     * @return array{org: int, event: int, session: int, slug: string, publicId: string, inventory: int}
     */
    private function seedEvent(string $status = EventStateMachine::PUBLISHED): array
    {
        [$orgId, $eventId, $sessionId, $inventoryId] = $this->seedSellableSeat(3);

        DB::table('events')->where('id', $eventId)->update([
            'status' => $status,
            'published_at' => now()->subMonth(),
        ]);

        $row = DB::table('events')->where('id', $eventId)->first();

        return [
            'org' => $orgId,
            'event' => $eventId,
            'session' => $sessionId,
            'slug' => (string) $row->slug,
            'publicId' => (string) $row->public_id,
            'inventory' => $inventoryId,
        ];
    }

    /** @param array{event: int} $event */
    private function touchEvent(array $event, array $values): void
    {
        DB::table('events')->where('id', $event['event'])->update($values);

        Cache::flush();
    }

    /** @param array{event: int} $event */
    private function statusOf(array $event): string
    {
        return (string) DB::table('events')->where('id', $event['event'])->value('status');
    }

    private function eventsSitemapXml(): string
    {
        $response = $this->get('/sitemap-events.xml');

        $response->assertStatus(200);

        return (string) $response->getContent();
    }

    private function indexXml(): string
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);

        return (string) $response->getContent();
    }

    /**
     * `lastmod` записи события из карты событий.
     *
     * Разбор регулярным выражением, а не `SimpleXML`: тест не должен падать из-за
     * отсутствия расширения в окружении — тогда «зелёный» результат зависел бы
     * от сборки PHP, а не от карты.
     */
    private function eventLastmod(string $slug): ?string
    {
        $xml = $this->eventsSitemapXml();

        $found = preg_match(
            '#<url>\s*<loc>[^<]*' . preg_quote($slug, '#') . '[^<]*</loc>\s*<lastmod>([^<]*)</lastmod>#s',
            $xml,
            $matches
        );

        $this->assertSame(1, $found, 'событие отсутствует в карте событий или у него нет lastmod');

        return $matches[1];
    }

    protected function tearDown(): void
    {
        // Карта кешируется на час (аудит P-10), поэтому без сброса следующий тест
        // получил бы XML предыдущего.
        Cache::flush();

        parent::tearDown();
    }
}
