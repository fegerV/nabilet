<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Modules\Notifications\Mail\TicketPurchasedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Support\AssetUrl;
use Nabilet\Modules\Notifications\Services\OrderNotificationData;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Tickets\Domain\Qr\QrEncoder;
use Nabilet\Modules\Tickets\Domain\Qr\QrPngRenderer;
use Nabilet\Modules\Tickets\Domain\TicketPalette;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Models\TicketTemplate;
use Nabilet\Modules\Tickets\Services\TicketGeneratorService;
use Nabilet\Modules\Tickets\Services\TicketTemplateResolver;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Tests\TestCase;

/**
 * Макет билета доезжает до письма покупателя.
 *
 * ПОВОД
 *
 * `events.ticket_template_id` существовал с прошлого этапа, но не влиял ни на
 * что: назначение макета не читал ни один потребитель. То есть «индивидуальный
 * билет под каждое мероприятие» было настройкой без последствий — админ выбирал
 * макет, а покупатель всё равно получал одну и ту же карточку.
 *
 * ПОЧЕМУ ЭТОТ ФАЙЛ НАЧИНАЕТСЯ С ПОЛОЖИТЕЛЬНОГО ТЕСТА
 *
 * Урок `REVIEW §3.25.2`: набор проверок из одних отказов не отличает рабочее
 * от сломанного. Здесь это особенно опасно, потому что ОБА потребителя
 * генератора (`OrderNotificationData::ticketsHtml/ticketsText` и
 * `NewsletterService::sendTicketNotification`) глотают `Throwable` и делают
 * `continue`. Письмо при этом уходит — просто без билетов, и заметить это
 * можно только на входе. Поэтому первый тест обязан пройти весь путь
 * `generateTicketData()` → переменные письма и утверждать, что билет внутри
 * реально есть. Тесты на фолбэки идут после него и опираются на то, что
 * основной путь доказан.
 *
 * ЧТО ИМЕННО ПРОВЕРЯЕТСЯ, ПО ТРЕБОВАНИЯМ ПЛАНА §6
 *  1. `qr_payload` не уходит наружу (в HTML он есть — это письмо покупателю,
 *     но ни одного внешнего адреса для отрисовки QR не появляется);
 *  2. аноним (`orders.user_id = NULL`) получает билет: резолв идёт от
 *     `tickets.event_id` / `session_id`, а не от пользователя;
 *  3. удаление макета не ломает заказ (`ON DELETE SET NULL` + фолбэк);
 *  4. положительный тест на письмо (выше);
 *  5. `template_json` остаётся произвольным JSON — он не типизируется, а из
 *     него безопасно извлекается только палитра.
 */
final class TicketEmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    private int $eventId;

    private int $sessionId;

    private int $orderId;

    private int $orderItemId;

    private int $ticketId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationId = $this->seedOrganization();

        // Постер задан: без него нечего проверять в блоке «афиша».
        $this->eventId = $this->seedEvent($this->organizationId, poster: 'events/posters/afisha.jpg');

        $venueId = $this->seedVenue($this->organizationId);
        $hallId = $this->seedHall($venueId);
        $schemaVersionId = $this->seedSchema($hallId);

        $this->sessionId = $this->seedSession($this->eventId, $venueId, $hallId, $schemaVersionId);

        // Настоящее место, а не NULL: подпись места в письме берётся из
        // `seats` → `hall_rows`, и тест обязан проверять именно этот путь.
        $seatId = $this->seedSeat($schemaVersionId);

        $inventoryItemId = $this->seedInventoryItem($this->sessionId, $seatId);

        // Заказ АНОНИМНЫЙ: `user_id = NULL`, контакт только по e-mail. Именно
        // так выглядит покупка на витрине без аккаунта, и именно на ней
        // ломается любой резолв «от пользователя».
        $this->orderId = $this->seedOrder($this->organizationId, $this->eventId, $this->sessionId);
        $this->orderItemId = $this->seedOrderItem($this->orderId, $inventoryItemId);
        $this->ticketId = $this->seedTicket($this->orderId, $this->orderItemId, $this->eventId, $this->sessionId, $inventoryItemId, $seatId);
    }

    // ── 1. Положительный путь: билет реально попадает в письмо ──────────────

    public function test_the_positive_path_puts_the_ticket_into_the_email(): void
    {
        $ticket = Ticket::query()->findOrFail($this->ticketId);

        // Сначала — сам генератор. Если он начнёт молча отдавать пустоту, тест
        // упадёт здесь, а не в вёрстке письма.
        $data = app(TicketGeneratorService::class)->generateTicketData($ticket);

        self::assertSame('TCK-TEST-001', $data['ticket_number']);
        self::assertSame('Тестовый концерт', $data['event_name']);
        self::assertSame('Партер / Место 12', $data['seats']);
        self::assertSame('NB1.test-public-id.token.signature', $data['qr_payload']);
        self::assertSame($this->baseUrl() . '/storage/events/posters/afisha.jpg', $data['poster_url']);
        self::assertSame($this->baseUrl() . '/#/tickets', $data['ticket_url']);

        // И теперь — то, ради чего всё: билет в переменных письма.
        $variables = app(OrderNotificationData::class)->build(Order::query()->findOrFail($this->orderId));

        self::assertArrayHasKey('tickets_html', $variables);
        self::assertArrayHasKey('tickets_text', $variables);

        $html = (string) $variables['tickets_html'];
        $text = (string) $variables['tickets_text'];

        self::assertStringContainsString('TCK-TEST-001', $html);
        self::assertStringContainsString('NB1.test-public-id.token.signature', $html);
        self::assertStringContainsString('Партер / Место 12', $html);
        self::assertStringContainsString('events/posters/afisha.jpg', $html);
        self::assertStringContainsString('Открыть билет', $html);

        // Текстовая версия — не пустая и содержит и билет, и ссылку.
        self::assertStringContainsString('TCK-TEST-001', $text);
        self::assertStringContainsString($this->baseUrl() . '/#/tickets', $text);
    }

    /**
     * `qr_payload` — подписанный токен входа. В письме покупателю он уместен
     * (это его билет), но он не должен уезжать третьей стороне: ни один
     * внешний адрес не имеет права получать его как параметр.
     *
     * Регрессия реальная: `lib/ticketBuilder.ts` отправляла payload в
     * `api.qrserver.com` как query-параметр.
     */
    public function test_the_qr_payload_is_not_handed_to_any_third_party(): void
    {
        $html = (string) app(OrderNotificationData::class)
            ->build(Order::query()->findOrFail($this->orderId))['tickets_html'];

        self::assertStringNotContainsString('qrserver', $html);
        self::assertStringNotContainsString('chart.googleapis', $html);

        // Payload присутствует ровно как текст кода, а не как параметр запроса.
        self::assertStringContainsString(
            'Код для входа: NB1.test-public-id.token.signature',
            $html
        );
    }

    // ── 2. Аноним получает билет ────────────────────────────────────────────

    public function test_an_anonymous_buyer_receives_the_ticket(): void
    {
        self::assertNull(
            DB::table('orders')->where('id', $this->orderId)->value('user_id'),
            'предусловие теста: заказ должен быть анонимным'
        );

        // Резолв макета не должен зависеть от пользователя: он идёт от
        // `tickets.event_id`.
        $templateId = $this->seedTemplate($this->organizationId, 'Анонимный макет', [
            'backgroundColor' => '#0b1220',
            'elements' => [['type' => 'rectangle', 'x' => 0, 'y' => 0, 'fill' => '#e11d48']],
        ]);
        DB::table('events')->where('id', $this->eventId)->update(['ticket_template_id' => $templateId]);

        $data = app(TicketGeneratorService::class)
            ->generateTicketData(Ticket::query()->findOrFail($this->ticketId));

        self::assertNotNull($data['template']);
        self::assertSame('Анонимный макет', $data['template']['name']);
        self::assertSame('#e11d48', $data['template']['accent_color']);
        self::assertSame('#0b1220', $data['template']['background_color']);

        $html = (string) app(OrderNotificationData::class)
            ->build(Order::query()->findOrFail($this->orderId))['tickets_html'];

        self::assertStringContainsString('TCK-TEST-001', $html);
        self::assertStringContainsString('data-template="Анонимный макет"', $html);
        self::assertStringContainsString('background:#0b1220', $html);
    }

    /**
     * Фолбэк «событие из сеанса».
     *
     * `tickets.event_id` объявлена NOT NULL, поэтому состояние «билета без
     * события» в здоровой базе недостижимо — и тест не имеет права подделывать
     * его UPDATE-ом, который БД отвергнет. Но резолвер читает поля МОДЕЛИ, а не
     * строку, и это правильный уровень проверки: строки, созданные до того, как
     * `TicketService` научился выводить событие из сеанса, в старых базах могли
     * остаться, и падать на них резолвер не должен.
     */
    public function test_the_template_is_resolved_through_the_session_when_the_ticket_has_no_event(): void
    {
        $templateId = $this->seedTemplate($this->organizationId, 'Макет из сеанса', [
            'elements' => [['type' => 'rectangle', 'x' => 0, 'y' => 0, 'fill' => '#7c3aed']],
        ]);
        DB::table('events')->where('id', $this->eventId)->update(['ticket_template_id' => $templateId]);

        $ticket = new Ticket();
        $ticket->event_id = null;
        $ticket->session_id = $this->sessionId;

        $resolved = app(TicketTemplateResolver::class)->resolveForTicket($ticket);

        self::assertNotNull($resolved, 'событие должно быть выведено из session_id');
        self::assertSame('Макет из сеанса', $resolved->name);
    }

    // ── 3. Фолбэки: удалённый макет и макет по умолчанию ────────────────────

    public function test_deleting_the_template_does_not_break_the_order(): void
    {
        $templateId = $this->seedTemplate($this->organizationId, 'Временный макет', [
            'elements' => [['type' => 'rectangle', 'x' => 0, 'y' => 0, 'fill' => '#111111']],
        ]);
        DB::table('events')->where('id', $this->eventId)->update(['ticket_template_id' => $templateId]);

        // Удаляем макет. FK `fk_events_ticket_template` объявлен с SET NULL,
        // поэтому мероприятие обязано остаться живым.
        TicketTemplate::query()->whereKey($templateId)->delete();

        self::assertNull(
            DB::table('events')->where('id', $this->eventId)->value('ticket_template_id'),
            'ON DELETE SET NULL должен обнулить ссылку'
        );

        // Заказ не сломан: билет по-прежнему собирается и попадает в письмо.
        $data = app(TicketGeneratorService::class)
            ->generateTicketData(Ticket::query()->findOrFail($this->ticketId));

        self::assertNull($data['template'], 'других макетов у организации нет — оформление по умолчанию');

        $html = (string) app(OrderNotificationData::class)
            ->build(Order::query()->findOrFail($this->orderId))['tickets_html'];

        self::assertStringContainsString('TCK-TEST-001', $html);
        self::assertStringContainsString(TicketPalette::DEFAULT_ACCENT, $html);
        self::assertStringNotContainsString('#111111', $html, 'палитра удалённого макета не должна остаться');
    }

    public function test_the_default_template_is_used_when_the_event_has_none_assigned(): void
    {
        self::assertNull(DB::table('events')->where('id', $this->eventId)->value('ticket_template_id'));

        $this->seedTemplate($this->organizationId, 'Макет организации', [
            'elements' => [['type' => 'rectangle', 'x' => 0, 'y' => 0, 'fill' => '#2563eb']],
        ]);

        $data = app(TicketGeneratorService::class)
            ->generateTicketData(Ticket::query()->findOrFail($this->ticketId));

        self::assertNotNull($data['template']);
        self::assertSame('Макет организации', $data['template']['name']);
        self::assertSame('#2563eb', $data['template']['accent_color']);
    }

    public function test_a_disabled_default_template_is_not_used(): void
    {
        $this->seedTemplate($this->organizationId, 'Выключенный макет', [
            'elements' => [['type' => 'rectangle', 'x' => 0, 'y' => 0, 'fill' => '#ff0000']],
        ], active: false);

        $data = app(TicketGeneratorService::class)
            ->generateTicketData(Ticket::query()->findOrFail($this->ticketId));

        self::assertNull($data['template'], 'выключенный макет не должен назначаться по умолчанию');
    }

    /**
     * Макет чужой организации не отдаётся: это была бы утечка чужого бренда в
     * письмо нашему покупателю.
     */
    public function test_a_template_from_another_organization_is_not_used(): void
    {
        $foreignOrganizationId = $this->seedOrganization('Другой организатор');

        $foreignTemplateId = $this->seedTemplate($foreignOrganizationId, 'Чужой макет', [
            'elements' => [['type' => 'rectangle', 'x' => 0, 'y' => 0, 'fill' => '#00ff00']],
        ]);
        DB::table('events')->where('id', $this->eventId)->update(['ticket_template_id' => $foreignTemplateId]);

        $data = app(TicketGeneratorService::class)
            ->generateTicketData(Ticket::query()->findOrFail($this->ticketId));

        self::assertNull($data['template'], 'чужой макет не должен использоваться');

        $html = (string) app(OrderNotificationData::class)
            ->build(Order::query()->findOrFail($this->orderId))['tickets_html'];

        self::assertStringNotContainsString('#00ff00', $html);
        self::assertStringNotContainsString('Чужой макет', $html);
    }

    // ── 4. Палитра: произвольный JSON и защита от разрыва атрибута ──────────

    public function test_the_palette_is_sanitised_so_it_cannot_break_out_of_the_style_attribute(): void
    {
        // `template_json` — произвольный JSON из админки. Значение цвета
        // попадает в атрибут `style="…"`, поэтому мусор здесь разорвал бы
        // атрибут и вставил разметку в письмо покупателя.
        $templateId = $this->seedTemplate($this->organizationId, 'Вредный макет', [
            'backgroundColor' => '#fff" onmouseover="alert(1)',
            'elements' => [
                ['type' => 'rectangle', 'x' => 0, 'y' => 0, 'fill' => 'javascript:alert(1)'],
            ],
        ]);
        DB::table('events')->where('id', $this->eventId)->update(['ticket_template_id' => $templateId]);

        $data = app(TicketGeneratorService::class)
            ->generateTicketData(Ticket::query()->findOrFail($this->ticketId));

        self::assertSame(TicketPalette::DEFAULT_BACKGROUND, $data['template']['background_color']);
        self::assertSame(TicketPalette::DEFAULT_ACCENT, $data['template']['accent_color']);

        $html = (string) app(OrderNotificationData::class)
            ->build(Order::query()->findOrFail($this->orderId))['tickets_html'];

        self::assertStringNotContainsString('onmouseover', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function test_the_palette_tolerates_arbitrary_template_json_shapes(): void
    {
        // `template_json` намеренно не типизирован — конструктор должен
        // развиваться без миграций. Значит, палитра обязана переживать любую
        // его форму и не ронять письмо.
        $cases = [
            'нет elements' => ['backgroundColor' => '#abcdef'],
            'elements не массив' => ['elements' => 'nope'],
            'элемент не массив' => ['elements' => ['nope']],
            'неизвестный тип' => ['elements' => [['type' => 'barcode', 'fill' => '#123456']]],
            'фигура без заливки' => ['elements' => [['type' => 'rectangle', 'x' => 1]]],
            'строковые координаты' => ['elements' => [['type' => 'circle', 'fill' => '#ABCDEF']]],
        ];

        foreach ($cases as $label => $json) {
            $palette = TicketPalette::fromTemplateJson($json);

            self::assertMatchesRegularExpression(
                '/^(#[0-9a-f]{3}|#[0-9a-f]{6}|black|white|transparent|inherit)$/',
                $palette->background,
                "фон должен быть валидным цветом: {$label}"
            );
            self::assertMatchesRegularExpression(
                '/^(#[0-9a-f]{3}|#[0-9a-f]{6}|black|white|transparent|inherit)$/',
                $palette->accent,
                "акцент должен быть валидным цветом: {$label}"
            );
        }

        // Строка с JSON (старая запись) разбирается, а не роняет палитру.
        $fromString = TicketPalette::fromTemplateJson('{"elements":[{"type":"rectangle","fill":"#654321"}]}');
        self::assertSame('#654321', $fromString->accent);

        // Мусор вместо JSON — значения по умолчанию, а не исключение.
        $fromGarbage = TicketPalette::fromTemplateJson('not json at all');
        self::assertSame(TicketPalette::DEFAULT_BACKGROUND, $fromGarbage->background);
        self::assertSame(TicketPalette::DEFAULT_ACCENT, $fromGarbage->accent);
    }

    /**
     * Верхний регистр hex приводится к нижнему: `#ABCDEF` и `#abcdef` — один
     * цвет, и сравнивать их как строки иначе нельзя.
     */
    public function test_the_palette_normalises_hex_case(): void
    {
        $palette = TicketPalette::fromTemplateJson([
            'backgroundColor' => '  #ABCDEF  ',
            'elements' => [['type' => 'rectangle', 'fill' => '#AABBCC']],
        ]);

        self::assertSame('#abcdef', $palette->background);
        self::assertSame('#aabbcc', $palette->accent);
    }

    // ── 5. Резолвер как отдельный контракт ─────────────────────────────────

    public function test_the_resolver_returns_null_instead_of_throwing_when_there_is_nothing_to_resolve(): void
    {
        // У организации нет ни одного макета. Резолвер обязан вернуть null, а
        // не бросить исключение: письмо с билетом не должно зависеть от того,
        // настроил ли администратор оформление.
        self::assertSame(0, TicketTemplate::query()->where('organization_id', $this->organizationId)->count());

        $resolved = app(TicketTemplateResolver::class)
            ->resolveForTicket(Ticket::query()->findOrFail($this->ticketId));

        self::assertNull($resolved);
    }

    /**
     * Ссылка на несуществующий макет (гонка: ссылку прочли, строку запросили)
     * тоже даёт фолбэк, а не падение. Состояние достижимо и без гонки: между
     * чтением `events.ticket_template_id` и запросом строки макет может быть
     * удалён другим запросом.
     */
    public function test_a_dangling_template_reference_falls_back_instead_of_failing(): void
    {
        // Ссылку ставим в обход FK — так выглядит состояние, которое резолвер
        // обязан пережить.
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        DB::table('events')->where('id', $this->eventId)->update(['ticket_template_id' => 424242]);
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');

        $data = app(TicketGeneratorService::class)
            ->generateTicketData(Ticket::query()->findOrFail($this->ticketId));

        self::assertNull($data['template']);

        $html = (string) app(OrderNotificationData::class)
            ->build(Order::query()->findOrFail($this->orderId))['tickets_html'];

        self::assertStringContainsString('TCK-TEST-001', $html);
    }

    public function test_the_generator_survives_a_ticket_that_is_not_persisted(): void
    {
        // У `TicketGeneratorService` есть потребитель, который собирает его
        // напрямую (`new TicketGeneratorService()`), и несохранённая модель не
        // должна уходить в базу за макетом.
        $ticket = new Ticket();
        $ticket->ticket_number = 'TCK-DRAFT-001';
        $ticket->qr_payload = 'NB1.draft.token.signature';

        $order = new Order();
        $order->customer_email = 'buyer@example.test';

        $ticket->setRelation('order', $order);

        $data = (new TicketGeneratorService())->generateTicketData($ticket);

        self::assertNull($data['template']);
        self::assertSame($this->baseUrl() . '/#/tickets', $data['ticket_url']);
    }

    // ── 6. Старый путь одиночного билета (TicketPurchasedMail) ─────────────

    /**
     * Второе письмо с билетом — `NewsletterService::sendTicketNotification` →
     * `TicketPurchasedMail` → `tickets::email.ticket` — оставалось в стороне от
     * оформления: шаблон рисовал фиксированный фиолетовый градиент, не
     * показывал афишу и не давал ссылки на страницу с билетом. Покупатель
     * получал письмо, из которого билет нельзя было открыть, а назначенный
     * макет мероприятия на этот канал не влиял вообще.
     *
     * Проверяем не «шаблон отрендерился», а что три вещи из
     * `generateTicketData()` доехали до разметки.
     */
    public function test_the_legacy_single_ticket_mail_renders_the_poster_and_the_button(): void
    {
        $ticket = Ticket::query()->findOrFail($this->ticketId);
        $data = app(TicketGeneratorService::class)->generateTicketData($ticket);

        $html = (new TicketPurchasedMail($ticket, $data))->render();

        self::assertStringContainsString('events/posters/afisha.jpg', $html);
        self::assertStringContainsString('Открыть билет', $html);
        self::assertStringContainsString($this->baseUrl() . '/#/tickets', $html);

        // Подписанный payload остаётся текстом-fallback и не уезжает третьей
        // стороне параметром запроса.
        self::assertStringContainsString('NB1.test-public-id.token.signature', $html);
        self::assertStringNotContainsString('qrserver', $html);
    }

    public function test_the_legacy_mail_omits_the_poster_when_the_event_has_none(): void
    {
        DB::table('events')->where('id', $this->eventId)->update(['poster' => null]);

        $ticket = Ticket::query()->findOrFail($this->ticketId);
        $data = app(TicketGeneratorService::class)->generateTicketData($ticket);

        $html = (new TicketPurchasedMail($ticket, $data))->render();

        // Битая картинка на месте заголовка хуже, чем её отсутствие. QR при
        // этом остаётся: у этих двух картинок разные причины появляться.
        self::assertStringNotContainsString('events/posters', $html);
        self::assertStringContainsString('data:image/png;base64,', $html);
        self::assertStringContainsString('Тестовый концерт', $html);
        self::assertStringContainsString('NB1.test-public-id.token.signature', $html);
    }

    /**
     * Акцент — фирменный цвет заказчика, а не наш, и он может быть светлым.
     * Белая надпись на жёлтой кнопке невидима: письмо уходит, вёрстка валидна,
     * ошибка видна только глазами. Поэтому цвет надписи вычисляется.
     */
    public function test_the_button_label_stays_readable_on_a_light_accent(): void
    {
        // Границы порога — прямыми значениями, без письма.
        self::assertSame(TicketPalette::DEFAULT_TEXT, TicketPalette::contrastText('#ffffff'));
        self::assertSame(TicketPalette::DEFAULT_TEXT, TicketPalette::contrastText('#fff'));
        self::assertSame(TicketPalette::DEFAULT_TEXT, TicketPalette::contrastText('white'));
        self::assertSame(TicketPalette::DEFAULT_TEXT, TicketPalette::contrastText('#fbbf24'));

        self::assertSame(TicketPalette::TEXT_ON_DARK, TicketPalette::contrastText('#000000'));
        self::assertSame(TicketPalette::TEXT_ON_DARK, TicketPalette::contrastText('black'));
        self::assertSame(TicketPalette::TEXT_ON_DARK, TicketPalette::contrastText(TicketPalette::DEFAULT_ACCENT));

        // Нераспознанный цвет считаем тёмным фоном: акцент по умолчанию тёмный.
        self::assertSame(TicketPalette::TEXT_ON_DARK, TicketPalette::contrastText('не цвет'));

        // И сквозь письмо: светлый макет не делает надпись невидимой.
        $templateId = $this->seedTemplate($this->organizationId, 'Светлый макет', [
            'backgroundColor' => '#ffffff',
            'elements' => [['type' => 'rectangle', 'fill' => '#fbbf24']],
        ]);
        DB::table('events')->where('id', $this->eventId)->update(['ticket_template_id' => $templateId]);

        $html = (string) app(OrderNotificationData::class)
            ->build(Order::query()->findOrFail($this->orderId))['tickets_html'];

        // Смотрим именно на кнопку: цвет текста встречается и в других местах
        // карточки, поэтому утверждение по всему письму ничего не доказывало бы.
        $buttonStart = strpos($html, 'role="button"');
        self::assertNotFalse($buttonStart, 'кнопка должна быть в письме');

        $button = substr($html, $buttonStart, 400);

        self::assertStringContainsString('background:#fbbf24', $button);
        self::assertStringContainsString('color:' . TicketPalette::DEFAULT_TEXT, $button);
    }

    // ── 7. QR в письме: локальный рендер, никакой третьей стороны ──────────

    /**
     * `qr_payload` — подписанный пропуск на вход. Раньше QR рисовал
     * `api.qrserver.com`, то есть токен уходил чужому сервису в
     * query-параметре. Теперь код строится в процессе и вкладывается как
     * data-URI.
     */
    public function test_the_qr_is_rendered_locally_and_embedded_in_both_emails(): void
    {
        $ticket = Ticket::query()->findOrFail($this->ticketId);
        $data = app(TicketGeneratorService::class)->generateTicketData($ticket);

        self::assertIsString($data['qr_data_uri']);
        self::assertStringStartsWith('data:image/png;base64,', $data['qr_data_uri']);

        $liveHtml = (string) app(OrderNotificationData::class)
            ->build(Order::query()->findOrFail($this->orderId))['tickets_html'];

        $legacyHtml = (new TicketPurchasedMail($ticket, $data))->render();

        // Оба канала обязаны получить QR: расхождение между ними уже один раз
        // оставляло покупателя без оформления.
        foreach (['живой путь' => $liveHtml, 'старый шаблон' => $legacyHtml] as $channel => $html) {
            self::assertStringContainsString('data:image/png;base64,', $html, "QR отсутствует: {$channel}");
            self::assertStringNotContainsString('qrserver', $html, $channel);
            self::assertStringNotContainsString('chart.googleapis', $html, $channel);

            // Точный инвариант: каждая картинка — либо вложенный data-URI (QR),
            // либо наш собственный адрес (афиша). Ничего чужого, куда мог бы
            // утечь подписанный токен, в письме быть не должно. Проверять
            // «нет http в src» нельзя: афиша как раз лежит на нашем хосте.
            preg_match_all('/<img[^>]+src="([^"]*)"/', $html, $matches);

            self::assertNotEmpty($matches[1], "в письме нет картинок: {$channel}");

            foreach ($matches[1] as $src) {
                self::assertTrue(
                    str_starts_with($src, 'data:image/png;base64,')
                        || str_starts_with($src, $this->baseUrl() . '/'),
                    "картинка ведёт на чужой адрес: {$src}"
                );
            }
        }
    }

    /**
     * Размер картинки обязан соответствовать матрице. Иначе код «на месте», но
     * обрезан или растянут — то есть не сканируется. Проверяется разбором PNG,
     * а не атрибутом `width` в разметке.
     */
    public function test_the_embedded_qr_png_has_the_expected_dimensions(): void
    {
        $data = app(TicketGeneratorService::class)
            ->generateTicketData(Ticket::query()->findOrFail($this->ticketId));

        $binary = base64_decode(
            substr((string) $data['qr_data_uri'], strlen('data:image/png;base64,')),
            true
        );

        self::assertIsString($binary);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $binary);

        $header = unpack('Nwidth/Nheight', substr($binary, 16, 8));

        // Payload билета из сева; масштаб и тихая зона — умолчания рендерера.
        $size = QrEncoder::encode('NB1.test-public-id.token.signature')['size'];
        $expected = ($size + 2 * QrPngRenderer::DEFAULT_QUIET_ZONE) * QrPngRenderer::DEFAULT_SCALE;

        self::assertSame($expected, $header['width']);
        self::assertSame($expected, $header['height']);
    }

    // ── 8. Общий помощник URL: два корня не должны слипнуться ───────────────

    /**
     * Афиша лежит в `storage/app/public` (`/storage/...`), заглушка — в
     * `public/` (от корня). Ошибка здесь незаметна: `og:image` для события без
     * постера однажды уже получал `/storage/images/og-default.svg` — 404,
     * который робот соцсети закэшировал на сутки.
     */
    public function test_asset_url_keeps_the_storage_and_public_roots_apart(): void
    {
        self::assertSame(
            $this->baseUrl() . '/storage/events/posters/a.jpg',
            AssetUrl::storage('events/posters/a.jpg')
        );
        self::assertSame(
            $this->baseUrl() . '/images/og-default.svg',
            AssetUrl::public('images/og-default.svg')
        );

        // Ведущий слэш не даёт двойного «//» в пути.
        self::assertSame(
            $this->baseUrl() . '/storage/a.jpg',
            AssetUrl::storage('/a.jpg')
        );

        // Уже абсолютный адрес возвращается как есть: в БД может лежать и
        // путь, и полный URL.
        self::assertSame('https://cdn.example.com/a.jpg', AssetUrl::storage('https://cdn.example.com/a.jpg'));
        self::assertSame('http://cdn.example.com/a.jpg', AssetUrl::public('http://cdn.example.com/a.jpg'));

        // Пустое значение — null, а не «https://host/storage/».
        self::assertNull(AssetUrl::storageOrNull(null));
        self::assertNull(AssetUrl::storageOrNull('   '));
    }

    // ── Сев ────────────────────────────────────────────────────────────────

    /**
     * База приложения без завершающего слэша — то же правило, что в
     * `AssetUrl::base()`. Ожидания строятся от неё, а не от литерала: иначе
     * тест проверял бы значение `APP_URL` в конкретном окружении, а не
     * различие корней `/storage/` и `/`.
     */
    private function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    private function seedOrganization(string $name = 'Сургут-Концерт'): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => 'org-' . Str::random(8),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedEvent(int $organizationId, ?string $poster = null): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => 'Тестовый концерт',
            'slug' => 'test-concert-' . Str::random(6),
            'status' => 'scheduled',
            'poster' => $poster,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedVenue(int $organizationId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'КЗ «Тестовый»',
            'slug' => 'venue-' . Str::random(8),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedHall(int $venueId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $venueId,
            'name' => 'Большой зал',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Версия схемы зала. `sessions.schema_version_id` — NOT NULL, поэтому без
     * опубликованной версии сеанс не создать.
     */
    private function seedSchema(int $hallId): int
    {
        return (int) HallSchemaVersion::create([
            'hall_id' => $hallId,
            'version' => 1,
            'revision' => 1,
            'status' => 'published',
            'published_at' => now(),
            'schema_json' => [
                'width' => 900,
                'height' => 520,
                'rows' => [
                    ['name' => 'Партер', 'seats' => [
                        ['number' => 12, 'label' => 'Место 12'],
                    ]],
                ],
            ],
        ])->id;
    }

    /**
     * Геометрия места: сектор → ряд → место.
     *
     * Цепочка обязательна целиком: `seats.row_id` и `hall_rows.sector_id` —
     * NOT NULL, а геометрия принадлежит версии схемы, а не сеансу.
     */
    private function seedSeat(int $schemaVersionId): int
    {
        $now = now()->toDateTimeString();

        $sectorId = (int) DB::table('sectors')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'schema_version_id' => $schemaVersionId,
            'name' => 'Партер',
            'code' => 'Parter',
            'type' => 'seated',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rowId = (int) DB::table('hall_rows')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'sector_id' => $sectorId,
            'number' => '1',
            'name' => 'Партер',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) DB::table('seats')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'row_id' => $rowId,
            'number' => '12',
            'label' => 'Место 12',
            'type' => 'standard',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedSession(int $eventId, int $venueId, int $hallId, int $schemaVersionId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('sessions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'event_id' => $eventId,
            'venue_id' => $venueId,
            'hall_id' => $hallId,
            'schema_version_id' => $schemaVersionId,
            'starts_at' => '2026-11-20 19:00:00',
            'timezone' => 'Asia/Yekaterinburg',
            'status' => 'scheduled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedInventoryItem(int $sessionId, int $seatId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('inventory_items')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'session_id' => $sessionId,
            'type' => 'seat',
            'seat_id' => $seatId,
            'price_amount' => 12500,
            'capacity' => 1,
            'available_quantity' => 0,
            'status' => 'sold',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedOrder(int $organizationId, int $eventId, int $sessionId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('orders')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'order_number' => 'NB-20261008-' . Str::random(4),
            'user_id' => null,
            'session_id' => $sessionId,
            'event_id' => $eventId,
            'organization_id' => $organizationId,
            'subtotal_amount' => 12500,
            'total_amount' => 12500,
            'currency' => 'RUB',
            'status' => 'paid',
            'payment_status' => 'succeeded',
            'customer_email' => 'buyer@example.test',
            'customer_name' => 'Анонимный Покупатель',
            'created_at' => $now,
            'paid_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedOrderItem(int $orderId, int $inventoryItemId): int
    {
        // У `order_items` нет `updated_at` — строка заказа неизменяема: правка
        // позиции после оплаты была бы подменой факта продажи.
        return (int) DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
            'unit_price' => 12500,
            'total_amount' => 12500,
            'event_title_snapshot' => 'Тестовый концерт',
            'seat_snapshot_json' => json_encode(['row' => 'Партер', 'seat' => 'Место 12']),
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    private function seedTicket(int $orderId, int $orderItemId, int $eventId, int $sessionId, int $inventoryItemId, int $seatId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('tickets')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'ticket_number' => 'TCK-TEST-001',
            'ticket_index' => 1,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'event_id' => $eventId,
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'seat_id' => $seatId,
            'holder_name' => 'Анонимный Покупатель',
            'status' => 'issued',
            'qr_version' => 1,
            'qr_token_hash' => hash('sha256', 'test-token'),
            'qr_payload' => 'NB1.test-public-id.token.signature',
            'issued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $templateJson
     */
    private function seedTemplate(int $organizationId, string $name, array $templateJson, bool $active = true): int
    {
        return (int) TicketTemplate::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'format' => 'mobile',
            'width' => 400,
            'height' => 600,
            'template_json' => $templateJson,
            'active' => $active,
        ])->id;
    }
}
