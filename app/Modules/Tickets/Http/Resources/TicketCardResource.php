<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

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
        ];
    }
}
