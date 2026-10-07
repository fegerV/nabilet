<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Services;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Tickets\Services\TicketGeneratorService;
use Throwable;

/**
 * Данные заказа для подстановки в шаблон письма.
 *
 * Собирает переменные, которые администратор видит в редакторе шаблонов.
 * Значения отдаются скалярами; блок билетов — `Htmlable`, чтобы сервис
 * отправки вставил его в HTML как есть (QR-код — разметка, её экранировать
 * нельзя), а в текстовой версии снял теги.
 */
class OrderNotificationData
{
    public function __construct(
        private readonly TicketGeneratorService $ticketGenerator,
    ) {}

    /**
     * @return array<string, scalar|Htmlable|null>
     */
    public function build(Order $order): array
    {
        $order->loadMissing(['event', 'session.venue', 'user']);

        $variables = [
            'customer_name' => $this->customerName($order),
            'order_number' => (string) $order->order_number,
            'event_name' => (string) ($order->event?->title ?? ''),
            'event_date' => $order->session?->starts_at?->format('d.m.Y H:i') ?? '',
            'venue_name' => (string) ($order->session?->venue?->name ?? ''),
            'total' => $this->money($order),
        ];

        // Билеты прикладываются только к оплаченному заказу. До оплаты их нет
        // физически, а в письмах об отмене и возврате они уже аннулированы —
        // показывать там QR-код значит подсказывать человеку несуществующий
        // билет.
        if ($order->status === OrderStateMachine::PAID) {
            $order->loadMissing('tickets');

            $variables['tickets_html'] = new HtmlString($this->ticketsHtml($order));
            $variables['tickets_text'] = $this->ticketsText($order);
        }

        return $variables;
    }

    private function customerName(Order $order): string
    {
        $name = trim((string) ($order->customer_name ?? ''));

        return $name !== '' ? $name : (string) ($order->user?->name ?? 'Покупатель');
    }

    private function money(Order $order): string
    {
        $amount = number_format(((int) $order->total_amount) / 100, 2, ',', ' ');
        $currency = (string) ($order->currency ?? 'RUB');

        return $amount . ' ' . match ($currency) {
            'RUB' => '₽',
            default => $currency,
        };
    }

    private function ticketsHtml(Order $order): string
    {
        $blocks = [];

        foreach ($order->tickets as $ticket) {
            try {
                $data = $this->ticketGenerator->generateTicketData($ticket);
            } catch (Throwable $e) {
                // Один нечитаемый билет не должен лишать покупателя письма
                // целиком: пропускаем его и пишем в лог.
                Log::warning('Билет не попал в письмо: не удалось подготовить данные.', [
                    'ticket_id' => $ticket->id,
                    'order_id' => $order->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            $blocks[] = $this->ticketBlock($data);
        }

        return implode('', $blocks);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ticketBlock(array $data): string
    {
        $seat = (string) ($data['seats'] ?? '');
        $number = (string) ($data['ticket_number'] ?? '');
        $qr = (string) ($data['qr_payload'] ?? '');

        return '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;'
            . 'margin:0 0 12px;background:#f9fafb;">'
            . '<div style="font-size:12px;color:#6b7280;text-transform:uppercase;">Билет</div>'
            . '<div style="font-size:18px;font-weight:700;margin:2px 0 8px;">' . e($number) . '</div>'
            . '<div style="font-size:14px;margin-bottom:8px;">Место: <b>' . e($seat) . '</b></div>'
            . '<div style="font-size:12px;color:#374151;word-break:break-all;">'
            . 'Код для входа: ' . e($qr) . '</div>'
            . '</div>';
    }

    private function ticketsText(Order $order): string
    {
        $lines = [];

        foreach ($order->tickets as $ticket) {
            try {
                $data = $this->ticketGenerator->generateTicketData($ticket);
            } catch (Throwable) {
                continue;
            }

            $lines[] = sprintf(
                'Билет %s — место %s, код %s',
                (string) ($data['ticket_number'] ?? ''),
                (string) ($data['seats'] ?? ''),
                (string) ($data['qr_payload'] ?? ''),
            );
        }

        return implode("\n", $lines);
    }
}
