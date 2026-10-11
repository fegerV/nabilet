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
 * Серверный текст внутри `#app` (SEO-аудит P-20).
 *
 * ДЕФЕКТ. Метаданные, JSON-LD и блок статуса отдавались сервером, но у ЖИВОГО
 * события весь видимый текст появлялся только после исполнения JavaScript: в
 * HTML лежал пустой `<div id="app">`. Последствия разные для разных потребителей:
 *
 *   - Google отрисует страницу, но с задержкой — новая афиша попадает в индекс
 *     позже, чем могла бы;
 *   - Яндекс рендерит хуже, поэтому для него страница почти пуста;
 *   - парсеры соцсетей не исполняют JS НИКОГДА: карточка ссылки строилась из
 *     одних метатегов, без единого слова о событии в теле.
 *
 * ЧТО ПРОВЕРЯЕТСЯ. В HTML обязан быть осмысленный текст о событии — заголовок,
 * дата, площадка, лид, цена — и он обязан быть ЭКРАНИРОВАН (текст приходит из
 * админки, то есть от пользователя), лежать ВНУТРИ `#app` (Vue очистит
 * контейнер, поэтому покупатель блок не увидит) и не создавать второй `<h1>` на
 * странице рядом с блоком статуса.
 */
final class EventServerContentTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const BLOCK = 'seo-content-fallback';

    // ── Основной контракт ──────────────────────────────────────────────────

    /** Живое событие отдаёт сервером заголовок, дату, площадку, цену и ссылку. */
    public function test_a_live_event_carries_server_rendered_body_content(): void
    {
        $block = $this->contentBlock($this->page($this->seedEvent()));

        $this->assertStringContainsString('<h1>Тестовое событие</h1>', $block);
        $this->assertStringContainsString('Дата и время:', $block);
        $this->assertStringContainsString('Площадка: Театр', $block);
        $this->assertStringContainsString('Билеты от 50 000,00 ₽', $block);
        $this->assertStringContainsString('Афиша всех мероприятий', $block);
    }

    /**
     * Блок лежит ВНУТРИ `#app`, а не рядом с ним.
     *
     * Это не деталь реализации, а условие корректности: Vue при монтировании
     * очищает контейнер и рисует приложение. Блок рядом с `#app` остался бы на
     * странице навсегда — покупатель видел бы дубль текста над интерфейсом.
     */
    public function test_the_body_block_lives_inside_the_app_container(): void
    {
        $html = $this->page($this->seedEvent());

        $this->assertSame(
            1,
            preg_match('/<div id="app">\s*<div class="' . self::BLOCK . '"/', $html),
            'серверный блок обязан быть первым содержимым #app'
        );
    }

    /**
     * На странице ровно один `<h1>` — в любом статусе.
     *
     * Два конкурирующих заголовка поисковик разрешает сам, и обычно не в пользу
     * того, который мы считаем главным. Поэтому контентный блок печатает
     * заголовок только тогда, когда блока статуса нет (у терминальных состояний
     * заголовок несёт статус).
     */
    public function test_a_page_has_exactly_one_heading(): void
    {
        foreach ([EventStateMachine::PUBLISHED, EventStateMachine::COMPLETED, EventStateMachine::CANCELLED] as $status) {
            $html = $this->page($this->seedEvent($status));

            $this->assertSame(
                1,
                substr_count($html, '<h1'),
                sprintf('на странице статуса «%s» больше одного <h1>', $status)
            );
        }
    }

    /** У терминального события соседствуют блок статуса и контентный блок. */
    public function test_a_terminal_event_keeps_the_status_block_and_adds_content(): void
    {
        $html = $this->page($this->seedEvent(EventStateMachine::COMPLETED));

        $this->assertStringContainsString('seo-status-fallback', $html, 'блок статуса пропал — регрессия P-05');

        $block = $this->contentBlock($html);

        // Заголовок здесь принадлежит блоку статуса, поэтому в контентном его нет.
        $this->assertStringNotContainsString('<h1>', $block);
        $this->assertStringContainsString('Площадка: Театр', $block);
    }

    /** Черновик страницы не имеет вовсе — и текста тоже. */
    public function test_the_body_content_is_not_rendered_for_a_draft(): void
    {
        $event = $this->seedEvent(EventStateMachine::DRAFT);

        $response = $this->get(route('events.show', [
            'slug' => $event['slug'],
            'publicId' => $event['publicId'],
        ]));

        $response->assertStatus(404);
        $this->assertStringNotContainsString(self::BLOCK, (string) $response->getContent());
    }

    // ── Лид ────────────────────────────────────────────────────────────────

    /** Лид берётся из `short_description` — текста, написанного для человека. */
    public function test_the_lead_uses_the_short_description(): void
    {
        $event = $this->seedEvent(shortDescription: 'Большой зимний концерт с оркестром.');

        $this->assertStringContainsString(
            'Большой зимний концерт с оркестром.',
            $this->contentBlock($this->page($event))
        );
    }

    /**
     * HTML из редактора снимается, а текст экранируется.
     *
     * Описание приходит из админки, то есть от пользователя. Вставка его в тело
     * страницы как разметки — это stored-XSS; `v-html` в проекте для
     * пользовательского текста запрещён, и серверный блок не исключение.
     */
    public function test_the_lead_strips_html_and_escapes_it(): void
    {
        $event = $this->seedEvent(
            description: '<p>Живой звук &amp; свет</p><script>alert(1)</script>',
        );

        $block = $this->contentBlock($this->page($event));

        $this->assertStringContainsString('Живой звук', $block);
        $this->assertStringNotContainsString('<script', $block, 'тег из описания попал в HTML');
        $this->assertStringNotContainsString('<p>Живой звук', $block, 'разметка описания не снята');
    }

    /** Название события в заголовке тоже экранируется. */
    public function test_the_title_is_escaped_in_the_heading(): void
    {
        $event = $this->seedEvent(title: 'Концерт <b>«Зима»</b>');

        $block = $this->contentBlock($this->page($event));

        $this->assertStringContainsString('Концерт &lt;b&gt;«Зима»&lt;/b&gt;', $block);
        $this->assertStringNotContainsString('<b>', $block);
    }

    /**
     * `</script>` в описании не разрывает страницу (регрессионная проверка §4.22).
     *
     * Описание — пользовательский текст, и с P-20 у него появилось ТРЕТЬЕ место
     * вывода: раньше только `meta description` и JSON-LD, теперь ещё и тело
     * страницы. Незакрытый `</script>` из описания закрыл бы тег раньше времени:
     * JSON-LD перестал бы разбираться, а всё, что шло после, попало бы в HTML как
     * есть. Проверка нужна именно потому, что мест стало больше.
     */
    public function test_a_description_with_a_script_tag_does_not_break_the_page(): void
    {
        $event = $this->seedEvent(
            description: 'Концерт</script><script>alert(1)</script>',
        );

        $response = $this->pageResponse($event);
        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)', $html);

        // Разметка обязана остаться разбираемой: если бы `</script>` из описания
        // закрыл тег, `json_decode()` внутри `jsonLd()` упал бы.
        $this->assertSame('Тестовое событие', $this->jsonLd($response)['name']);

        $block = $this->contentBlock($html);

        $this->assertStringContainsString('Концерт', $block);
        $this->assertStringNotContainsString('<script', $block);
    }

    // ── Дата и площадка ────────────────────────────────────────────────────

    /**
     * Дополнительные сеансы называются числом, а не перечисляются.
     *
     * У события их может быть десятки; перечисление в серверном блоке — это
     * раздувание страницы без пользы для краулера, которому достаточно знать,
     * что сеансов несколько.
     */
    public function test_the_session_line_counts_extra_sessions(): void
    {
        $event = $this->seedEvent();

        $this->assertStringNotContainsString(
            'всего сеансов',
            $this->contentBlock($this->page($event)),
            'у события с одним сеансом счётчика быть не должно'
        );

        $this->duplicateSession($event['session']);

        $this->assertStringContainsString(
            'всего сеансов: 2',
            $this->contentBlock($this->page($event))
        );
    }

    /**
     * Пустой адрес площадки не оставляет висящую запятую.
     *
     * `venues.address` необязателен. Наивная склейка дала бы
     * «Площадка: Театр, , » — текст, который читается как сбой рендеринга.
     */
    public function test_the_venue_line_has_no_dangling_commas(): void
    {
        $block = $this->contentBlock($this->page($this->seedEvent()));

        $this->assertStringContainsString('Площадка: Театр', $block);
        $this->assertStringNotContainsString('Площадка: Театр,', $block);
    }

    /** Город и адрес добавляются, когда они есть. */
    public function test_the_venue_line_includes_city_and_address_when_present(): void
    {
        $event = $this->seedEvent();

        DB::table('venues')->update(['city' => 'Сургут', 'address' => 'ул. Ленина, 1']);

        $block = $this->contentBlock($this->page($event));

        $this->assertStringContainsString('Площадка: Театр, Сургут, ул. Ленина, 1', $block);
    }

    // ── Цена ───────────────────────────────────────────────────────────────

    /**
     * Цена в тексте и цена в разметке — одна и та же величина.
     *
     * Обе берутся из `offerPayload()`, поэтому расхождение здесь означало бы,
     * что страница обещает покупателю не то, что видит поисковик.
     */
    public function test_the_price_line_matches_the_json_ld_offer(): void
    {
        $response = $this->pageResponse($this->seedEvent());

        $block = $this->contentBlock((string) $response->getContent());
        $jsonLd = $this->jsonLd($response);

        $this->assertStringContainsString('Билеты от 50 000,00 ₽', $block);
        $this->assertSame('50000.00', $jsonLd['offers']['price']);
    }

    /** Распроданное живое событие говорит об этом текстом, а не молчанием. */
    public function test_a_sold_out_event_announces_it(): void
    {
        $block = $this->contentBlock($this->page($this->seedEvent(available: 0)));

        $this->assertStringContainsString('Все билеты проданы', $block);
    }

    /**
     * Отменённое событие НЕ объявляет билеты проданными.
     *
     * «Все билеты проданы» рядом с «мероприятие отменено» — противоречие:
     * отменённое не распродано. Строку заменяет блок статуса.
     */
    public function test_a_cancelled_event_does_not_claim_the_tickets_are_sold_out(): void
    {
        $block = $this->contentBlock($this->page($this->seedEvent(EventStateMachine::CANCELLED, available: 0)));

        $this->assertStringNotContainsString('Все билеты проданы', $block);
    }

    // ── Фикстуры ───────────────────────────────────────────────────────────

    /**
     * @return array{org: int, event: int, session: int, slug: string, publicId: string}
     */
    private function seedEvent(
        string $status = EventStateMachine::PUBLISHED,
        int $available = 3,
        ?string $title = null,
        ?string $shortDescription = null,
        ?string $description = null,
    ): array {
        [$orgId, $eventId, $sessionId] = $this->seedSellableSeat(
            available: $available,
            eventTitle: $title ?? 'Тестовое событие',
        );

        $updates = ['status' => $status];

        if ($shortDescription !== null) {
            $updates['short_description'] = $shortDescription;
        }

        if ($description !== null) {
            $updates['description'] = $description;
        }

        DB::table('events')->where('id', $eventId)->update($updates);

        $row = DB::table('events')->where('id', $eventId)->first();

        return [
            'org' => $orgId,
            'event' => $eventId,
            'session' => $sessionId,
            'slug' => (string) $row->slug,
            'publicId' => (string) $row->public_id,
        ];
    }

    /** Второй сеанс того же события — на той же площадке и в том же зале. */
    private function duplicateSession(int $sessionId): void
    {
        $session = (array) DB::table('sessions')->where('id', $sessionId)->first();

        unset($session['id']);

        $session['public_id'] = (string) \Illuminate\Support\Str::ulid()->toBase32();
        $session['starts_at'] = now()->addWeeks(2)->toDateTimeString();

        DB::table('sessions')->insert($session);
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
     * Содержимое контентного блока.
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

    /** @return array<string, mixed> */
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
