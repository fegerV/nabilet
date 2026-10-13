<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Nabilet\Modules\Tickets\Models\Ticket;

/**
 * Карточка билета покупателя (витрина «Мои билеты»).
 *
 * Форма строго совпадает с интерфейсом `TicketCard` в resources/js/lib/types.ts,
 * чтобы TicketsPage.vue мог отрисовать ответ без дополнительной трансформации.
 *
 * Источник истины о месте — seat.row.sector / seat.row.number / seat.number.
 * Билеты стоячих зон и прочих безместных тарифов места не имеют — тогда
 * подставляем снапшоты из orderItem и прочерки, чтобы карточка не развалилась.
 */
class TicketCardResource extends JsonResource
{
    public function toArray($request): array
    {
        $ticket = $this->resource;
        $seat = $ticket->seat;
        $row = $seat?->row;
        $orderItem = $ticket->orderItem;
        $session = $ticket->session;
        $event = $ticket->event;

        return [
            'id' => $ticket->public_id,
            'code' => $ticket->ticket_number,
            'eventTitle' => $orderItem?->event_title_snapshot ?? $event?->title ?? '—',
            'venue' => $orderItem?->venue_title_snapshot ?? $session?->venue?->name ?? '—',
            'sessionAt' => $session?->starts_at?->toIso8601String(),
            'sector' => $row?->sector?->name ?? $ticket->standingZone?->name ?? 'Входной билет',
            'row' => $row?->number ?? 0,
            'seat' => $seat?->number ?? 0,
            'priceMinor' => (int) ($orderItem?->unit_price ?? 0),
            'status' => $ticket->status,
            'qrPayload' => $ticket->status === 'issued' ? $ticket->qr_payload : null,
            'holder' => $ticket->order?->customer_name ?? null,
            'orderId' => $ticket->order?->public_id,
            'template' => $this->resolveTemplate($ticket),
        ];
    }

    /**
     * Макет билета, назначенный мероприятию, — для рендера полного холста на
     * витрине «Мои билеты». Возвращаем ровно то, что нужно фронтенду для
     * абсолютного позиционирования: размеры, фон (сплошной или градиент) и
     * элементы. `null` — макет не назначен, фронт показывает семантическую карточку.
     *
     * ТИП АРГУМЕНТА БЫЛ НЕРАЗРЕШИМ. Здесь стояло `Ticket $ticket` без импорта, а
     * класс объявлен в пространстве `…\Http\Resources`, где никакого `Ticket` нет.
     * PHP поэтому ждал `Nabilet\Modules\Tickets\Http\Resources\Ticket` и падал с
     * `TypeError: … must be of type …Http\Resources\Ticket, …Models\Ticket given`
     * на КАЖДОМ билете — то есть `GET /api/v1/my-tickets` отвечал 500 всегда,
     * как только у покупателя появлялся хотя бы один билет. Ни один тест этого не
     * ловил: единственный файл, трогавший `/my-tickets`, проверял соседний
     * `POST /my-tickets/resend`.
     */
    private function resolveTemplate(Ticket $ticket): ?array
    {
        $template = $ticket->event?->ticketTemplate;

        if ($template === null) {
            return null;
        }

        $tj = is_array($template->template_json) ? $template->template_json : null;
        if (! is_array($tj)) {
            return null;
        }

        $elements = is_array($tj['elements'] ?? null) ? $tj['elements'] : [];

        return [
            'width' => (int) ($template->width ?? 400),
            'height' => (int) ($template->height ?? 600),
            'backgroundColor' => is_string($tj['backgroundColor'] ?? null) ? $tj['backgroundColor'] : '#ffffff',
            'backgroundGradient' => is_array($tj['backgroundGradient'] ?? null) ? $tj['backgroundGradient'] : null,
            'elements' => $elements,
        ];
    }
}
