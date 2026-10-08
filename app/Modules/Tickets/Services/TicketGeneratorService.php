<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Services;

use Illuminate\Support\Facades\Log;
use Nabilet\Core\Support\AssetUrl;
use Nabilet\Modules\Tickets\Domain\Qr\QrCapacityException;
use Nabilet\Modules\Tickets\Domain\Qr\QrEncoder;
use Nabilet\Modules\Tickets\Domain\Qr\QrPngRenderer;
use Nabilet\Modules\Tickets\Domain\TicketPalette;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Models\TicketTemplate;

/**
 * Builds the data contract for the customer-facing ticket email.
 *
 * Ticket issuance and QR signing are owned by TicketService. This class only
 * reads an issued ticket; it never invents a booking model, unsigned QR, or
 * legacy hall schema.
 *
 * QR-КАРТИНКА РИСУЕТСЯ ЛОКАЛЬНО
 *
 * Раньше здесь стояло «QR raster rendering is intentionally not attempted».
 * Это было верно ровно до тех пор, пока не появился `Domain/Qr/QrEncoder`:
 * подписанный payload нельзя отдавать сервису картинок (это утечка пропуска),
 * а пакета QR в `vendor/` нет, поэтому кодировщик написан свой и сверяется с
 * независимой реализацией побитово (см. `QrEncoderGoldenMasterTest`).
 *
 * Картинка отдаётся как `data:image/png;base64,…` — единственный способ
 * вложить код в письмо, не отдавая payload наружу. Ограничение честное и
 * известное: Gmail вырезает `data:`-URI в `src`, поэтому QR — удобство для
 * клиентов, которые их показывают, а гарантированный путь остаётся прежним:
 * текстовый payload рядом и кнопка на страницу с билетом.
 *
 * МАКЕТ БИЛЕТА
 *
 * Раньше `events.ticket_template_id` существовал, но не влиял ни на что:
 * назначение макета не читал ни один потребитель, поэтому «индивидуальный
 * билет под мероприятие» оставался настройкой без последствий. Теперь макет
 * резолвится здесь (`TicketTemplateResolver`) и уезжает в письмо в виде
 * палитры — см. `TicketPalette` о том, почему переносится только цвет, а не
 * холст целиком.
 *
 * Резолвер инжектится необязательным: у класса есть потребитель, который
 * собирает его напрямую (`new TicketGeneratorService()` в тесте контракта на
 * несохранённом билете). Для несохранённого билета резолв пропускается —
 * читать `events.ticket_template_id` не из чего.
 */
class TicketGeneratorService
{
    public function __construct(
        private readonly ?TicketTemplateResolver $templates = null,
    ) {}

    /**
     * @return array{
     *   order_id:int,
     *   ticket_number:string,
     *   event_name:string,
     *   event_date:string,
     *   venue_name:string,
     *   seats:string,
     *   price:float,
     *   currency:string,
     *   customer_name:string,
     *   customer_email:string,
     *   qr_payload:string,
     *   unique_hash:string,
     *   poster_url:string|null,
     *   ticket_url:string,
     *   qr_data_uri:string|null,
     *   template:array{id:int,name:string,format:string,width:int,height:int,background_color:string,accent_color:string}|null
     * }
     */
    public function generateTicketData(Ticket $ticket): array
    {
        $ticket->loadMissing([
            'order.user',
            'orderItem',
            'event',
            'session.venue',
            'seat.row',
            'standingZone',
        ]);

        $order = $ticket->order;
        if ($order === null) {
            throw new \LogicException('Cannot prepare a ticket email without its order.');
        }
        if (! is_string($ticket->qr_payload) || $ticket->qr_payload === '') {
            throw new \LogicException('Cannot email a ticket without its signed QR payload.');
        }

        $event = $ticket->event;
        $session = $ticket->session;
        $seat = $ticket->seat;
        $standingZone = $ticket->standingZone;
        $seatLabel = $seat !== null
            ? trim(($seat->row?->name ? $seat->row->name . ' / ' : '') . ($seat->label ?: 'Место ' . $seat->number))
            : ($standingZone?->name ?? 'Свободная рассадка');

        $minorAmount = (int) ($ticket->orderItem?->unit_price ?? 0);

        // Макет и палитра. `$event` может быть null (билет старой базы без
        // события) — тогда оформления нет, и это штатно: билет уходит в
        // стандартном виде, а не остаётся без письма.
        $template = $this->templateFor($ticket);
        $palette = TicketPalette::fromTemplateJson($template?->template_json);

        return [
            'order_id' => (int) $order->id,
            'ticket_number' => (string) $ticket->ticket_number,
            'event_name' => (string) ($event?->title ?? 'Мероприятие'),
            'event_date' => $session?->starts_at?->format('d.m.Y H:i') ?? '',
            'venue_name' => (string) ($session?->venue?->name ?? ''),
            'seats' => $seatLabel,
            'price' => $minorAmount / 100,
            'currency' => (string) ($order->currency ?? 'RUB'),
            'customer_name' => (string) ($order->customer_name ?? $order->user?->name ?? 'Покупатель'),
            'customer_email' => (string) $order->customer_email,
            'qr_payload' => (string) $ticket->qr_payload,
            'unique_hash' => (string) $ticket->public_id,
            // Афиша — из мероприятия, не из макета: макет описывает раскладку,
            // а картинка у каждого мероприятия своя (см. план §4.2). Постер
            // лежит в `storage/app/public`, поэтому URL идёт через `/storage/`.
            'poster_url' => AssetUrl::storageOrNull($event?->poster),
            'ticket_url' => $this->ticketUrl(),
            // Готовый QR как data-URI. Один источник для ОБОИХ писем — и для
            // живого пути (`OrderNotificationData`), и для шаблона
            // `tickets::email.ticket`: расхождение между этими каналами уже
            // один раз оставляло покупателя без оформления.
            'qr_data_uri' => $this->qrDataUri((string) $ticket->qr_payload),
            'template' => $template === null ? null : [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'format' => (string) ($template->format ?? 'mobile'),
                'width' => (int) ($template->width ?? 0),
                'height' => (int) ($template->height ?? 0),
                'background_color' => $palette->background,
                'accent_color' => $palette->accent,
            ],
        ];
    }

    /**
     * Макет билета для этого билета, либо `null`.
     *
     * Несохранённый билет резолвить не от чего: `events.ticket_template_id`
     * читается из БД по `event_id`, а у модели «в памяти» такого id нет.
     * Проверка не косметическая — без неё прямое `new TicketGeneratorService()`
     * на несохранённой модели уходило бы в базу.
     */
    private function templateFor(Ticket $ticket): ?TicketTemplate
    {
        if (! $ticket->exists) {
            return null;
        }

        return $this->templates()->resolveForTicket($ticket);
    }

    /**
     * Резолвер из контейнера, если он не был инжектнут.
     *
     * `TicketTemplateResolver` без зависимостей, поэтому контейнер собирает его
     * без настройки; ленивое разрешение нужно только для прямого
     * `new TicketGeneratorService()`.
     */
    private function templates(): TicketTemplateResolver
    {
        return $this->templates ?? app(TicketTemplateResolver::class);
    }

    /**
     * Ссылка на страницу «Мои билеты» в витрине.
     *
     * Витрина — SPA на хеш-роутинге (`createWebHashHistory`), поэтому путь
     * живёт во фрагменте: серверная часть о нём не знает и знать не должна.
     * Именно сюда ведёт кнопка в письме; QR рисует браузер покупателя, поэтому
     * подписанный payload не покидает его устройство.
     */
    private function ticketUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/#/tickets';
    }

    /**
     * QR билета как `data:image/png;base64,…`, либо `null`.
     *
     * `null` — не ошибка, а деградация: payload может не влезть в
     * поддерживаемые версии (в реальности — нет, запас двукратный). В этом
     * случае письмо обязано уйти как раньше, с текстовым payload, поэтому
     * исключение ловится здесь, а не всплывает до отправителя. Обратное —
     * «письмо без билета из-за картинки» — недопустимо.
     */
    private function qrDataUri(string $payload): ?string
    {
        try {
            return QrPngRenderer::dataUri(QrEncoder::encode($payload));
        } catch (QrCapacityException $e) {
            Log::warning('QR билета не построен — письмо уйдёт с текстовым payload.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
