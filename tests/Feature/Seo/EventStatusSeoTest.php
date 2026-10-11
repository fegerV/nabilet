<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Nabilet\Modules\Events\StateMachines\EventStateMachine;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Страница события для завершённых и отменённых мероприятий (SEO-аудит P-05, P-17, P-18).
 *
 * ДЕФЕКТ. Метаданные и JSON-LD отдавались сервером, но и заголовок, и описание,
 * и ВЕСЬ видимый текст не зависели от статуса:
 *
 *   - прошедшее событие отдавало «… — купить билеты» и описание
 *     «Билеты на „X“: выбор мест на схеме зала, оплата онлайн» — то есть
 *     сниппет в выдаче обещал покупку, которой на странице нет;
 *   - `availability` считался по инвентарю, а инвентарь прошедшего события никто
 *     не чистит: непроданные места оставались `available`, и в разметку уезжал
 *     `InStock` для концерта, который уже состоялся;
 *   - отменённое событие отдавало 200 + `noindex`, но видимого текста об отмене
 *     не было вовсе — краулер без JS видел обычную витрину покупки, тогда как
 *     разметка говорила `EventCancelled`. Расхождение разметки и контента
 *     поисковик трактует как введение в заблуждение.
 *
 * ЧТО ПРОВЕРЯЕТСЯ. Заголовок, описание, `availability`, `eventStatus` и видимый
 * серверный блок статуса обязаны соответствовать статусу события — и НЕ ломать
 * страницу опубликованного события.
 */
final class EventStatusSeoTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    // ── Завершённое событие ────────────────────────────────────────────────

    /** Заголовок и описание прошедшего события говорят, что оно прошло. */
    public function test_a_completed_event_does_not_advertise_ticket_sales(): void
    {
        $event = $this->seedEvent(EventStateMachine::COMPLETED);

        $html = $this->page($event);

        $this->assertStringContainsString('<title>Тестовое событие — мероприятие состоялось</title>', $html);
        $this->assertStringNotContainsString('— купить билеты', $html);

        $this->assertStringContainsString('Мероприятие состоялось', $html);
        $this->assertStringContainsString('Билеты больше не продаются', $html);
    }

    /**
     * `availability` прошедшего события — `SoldOut`, хотя места в инвентаре есть.
     *
     * Это ключевая часть фикса: инвентарь прошедшего события не чистится, поэтому
     * наличие мест в нём ничего не значит. Купить билет на прошедший концерт
     * нельзя, чем бы ни был инвентарь.
     */
    public function test_a_completed_event_reports_sold_out_even_with_free_seats(): void
    {
        // Места есть — 5 доступных позиций.
        $event = $this->seedEvent(EventStateMachine::COMPLETED, available: 5);

        $jsonLd = $this->jsonLd($this->pageResponse($event));

        $this->assertSame('https://schema.org/EventCompleted', $jsonLd['eventStatus']);
        $this->assertSame('https://schema.org/SoldOut', $jsonLd['offers']['availability']);
    }

    /** Видимый серверный блок статуса присутствует — для краулеров без JS. */
    public function test_a_completed_event_has_a_server_rendered_status_block(): void
    {
        $html = $this->page($this->seedEvent(EventStateMachine::COMPLETED));

        $this->assertStringContainsString('data-event-status="completed"', $html);
        $this->assertStringContainsString('seo-status-fallback', $html);
    }

    // ── Отменённое событие ─────────────────────────────────────────────────

    /** Отменённое событие сообщает об отмене и в заголовке, и в описании. */
    public function test_a_cancelled_event_announces_the_cancellation(): void
    {
        $html = $this->page($this->seedEvent(EventStateMachine::CANCELLED));

        $this->assertStringContainsString('<title>Тестовое событие — мероприятие отменено</title>', $html);
        $this->assertStringContainsString('Мероприятие отменено', $html);
        $this->assertStringContainsString('Билеты не продаются', $html);
    }

    /**
     * Отменённое событие — `OutOfStock` и `noindex`, но остаётся доступным.
     *
     * 404 здесь был бы ошибкой: адрес попал в письма и закладки, и человек,
     * пришедший по нему, должен увидеть, что концерт отменён, а не «страница не
     * найдена». В индекс такая страница не идёт — в sitemap её тоже нет.
     */
    public function test_a_cancelled_event_is_out_of_stock_and_noindexed_but_reachable(): void
    {
        $response = $this->pageResponse($this->seedEvent(EventStateMachine::CANCELLED));

        $response->assertStatus(200);

        $jsonLd = $this->jsonLd($response);

        $this->assertSame('https://schema.org/EventCancelled', $jsonLd['eventStatus']);
        $this->assertSame('https://schema.org/OutOfStock', $jsonLd['offers']['availability']);

        $this->assertStringContainsString('noindex', (string) $response->getContent());
        $this->assertStringContainsString('data-event-status="cancelled"', (string) $response->getContent());
    }

    // ── Опубликованное событие не сломано ──────────────────────────────────

    /** Живое событие по-прежнему продаёт и не получает блок статуса. */
    public function test_a_published_event_still_advertises_ticket_sales(): void
    {
        $event = $this->seedEvent(EventStateMachine::PUBLISHED);

        $response = $this->pageResponse($event);
        $html = (string) $response->getContent();

        $this->assertStringContainsString('<title>Тестовое событие — купить билеты</title>', $html);
        $this->assertStringContainsString('Билеты на «Тестовое событие»', $html);
        $this->assertStringNotContainsString('seo-status-fallback', $html, 'у живого события нет статусного блока');

        $jsonLd = $this->jsonLd($response);

        $this->assertSame('https://schema.org/EventScheduled', $jsonLd['eventStatus']);
        $this->assertSame('https://schema.org/InStock', $jsonLd['offers']['availability']);
    }

    /** Живое событие без доступных мест остаётся `SoldOut` — это не регрессия. */
    public function test_a_published_event_without_free_seats_is_sold_out(): void
    {
        $event = $this->seedEvent(EventStateMachine::PUBLISHED, available: 0);

        $jsonLd = $this->jsonLd($this->pageResponse($event));

        $this->assertSame('https://schema.org/SoldOut', $jsonLd['offers']['availability']);
    }

    // ── Авторские значения важнее автоматики ───────────────────────────────

    /**
     * `seo_title` из БД побеждает и для завершённого события.
     *
     * Автоматика не должна спорить с человеком: если администратор написал
     * заголовок, он и уходит. Значение по умолчанию — это именно значение ПО
     * УМОЛЧАНИЮ.
     */
    public function test_an_explicit_seo_title_wins_for_a_completed_event(): void
    {
        $event = $this->seedEvent(EventStateMachine::COMPLETED, seoTitle: 'Как это было: тестовое событие');

        $this->assertStringContainsString(
            '<title>Как это было: тестовое событие</title>',
            $this->page($event)
        );
    }

    /** `seo_description` из БД тоже побеждает. */
    public function test_an_explicit_seo_description_wins_for_a_completed_event(): void
    {
        $event = $this->seedEvent(
            EventStateMachine::COMPLETED,
            seoDescription: 'Отчёт о прошедшем концерте с фотографиями.',
        );

        $this->assertStringContainsString(
            'content="Отчёт о прошедшем концерте с фотографиями."',
            $this->page($event)
        );
    }

    /**
     * Пустой `seo_title` — это не заголовок.
     *
     * Раньше использовался оператор `??`, который пустую строку считает
     * значением: событие с `seo_title = ''` уезжало в выдачу с ПУСТЫМ `<title>`.
     */
    public function test_an_empty_seo_title_falls_back_to_the_generated_one(): void
    {
        $event = $this->seedEvent(EventStateMachine::PUBLISHED, seoTitle: '');

        $this->assertStringContainsString(
            '<title>Тестовое событие — купить билеты</title>',
            $this->page($event)
        );
    }

    // ── P-18: og:image ─────────────────────────────────────────────────────

    /**
     * Заглушка `og:image` — РАСТРОВАЯ, с alt и размерами.
     *
     * SVG не читают парсеры VK и iMessage, а именно через них распространяются
     * афиши. Размеры заглушки известны, потому что её рисует наш генератор, —
     * значит, парсеру не нужно скачивать файл, чтобы построить превью.
     */
    public function test_the_og_image_fallback_is_a_raster_with_alt_and_dimensions(): void
    {
        $html = $this->page($this->seedEvent(EventStateMachine::PUBLISHED));

        $this->assertStringContainsString('og:image" content="' . config('app.url') . '/images/og-default.png', $html);
        $this->assertStringNotContainsString('og-default.svg', $html, 'SVG-заглушка не читается частью парсеров');
        $this->assertStringContainsString('<meta property="og:image:alt"', $html);
        $this->assertStringContainsString('<meta property="og:image:width" content="1200" />', $html);
        $this->assertStringContainsString('<meta property="og:image:height" content="630" />', $html);
    }

    /**
     * У события с постером размеры НЕ выводятся.
     *
     * Их пришлось бы читать с диска на каждый рендер, а неверное значение хуже
     * отсутствующего: парсер обрежет превью именно по нему.
     */
    public function test_a_poster_gets_an_alt_but_no_guessed_dimensions(): void
    {
        $event = $this->seedEvent(EventStateMachine::PUBLISHED, poster: 'events/posters/a.jpg');

        $html = $this->page($event);

        $this->assertStringContainsString('/storage/events/posters/a.jpg', $html);
        $this->assertStringContainsString('Афиша мероприятия «Тестовое событие»', $html);
        $this->assertStringNotContainsString('og:image:width', $html);
    }

    /** `og:locale` присутствует: без него соцсеть выбирает язык превью сама. */
    public function test_the_og_locale_is_declared(): void
    {
        $this->assertStringContainsString(
            '<meta property="og:locale" content="ru_RU" />',
            $this->page($this->seedEvent(EventStateMachine::PUBLISHED))
        );
    }

    // ── Фикстуры ───────────────────────────────────────────────────────────

    /**
     * @return array{org: int, event: int, session: int, slug: string, publicId: string, inventory: int}
     */
    private function seedEvent(
        string $status = EventStateMachine::PUBLISHED,
        int $available = 3,
        ?string $seoTitle = null,
        ?string $seoDescription = null,
        ?string $poster = null,
    ): array {
        [$orgId, $eventId, $sessionId, $inventoryId] = $this->seedSellableSeat(available: $available);

        $updates = ['status' => $status];

        if ($seoTitle !== null) {
            $updates['seo_title'] = $seoTitle;
        }

        if ($seoDescription !== null) {
            $updates['seo_description'] = $seoDescription;
        }

        if ($poster !== null) {
            $updates['poster'] = $poster;
        }

        DB::table('events')->where('id', $eventId)->update($updates);

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

    /**
     * @return array<string, mixed>
     */
    private function jsonLd(TestResponse $response): array
    {
        $html = (string) $response->getContent();

        $this->assertSame(
            1,
            preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches),
            'в HTML нет блока JSON-LD'
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
