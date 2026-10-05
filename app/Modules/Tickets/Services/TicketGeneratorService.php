<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Services;

use Nabilet\Modules\Tickets\Models\Ticket;

/**
 * Builds the data contract for the customer-facing ticket email.
 *
 * Ticket issuance and QR signing are owned by TicketService. This class only
 * reads an issued ticket; it never invents a booking model, unsigned QR, or
 * legacy hall schema. QR raster/PDF rendering is intentionally not attempted:
 * no QR or PDF package is installed, and a signed payload must not be sent to a
 * third-party image service.
 */
class TicketGeneratorService
{
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
     *   unique_hash:string
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
        ];
    }
}
