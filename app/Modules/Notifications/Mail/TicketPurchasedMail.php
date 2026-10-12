<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Services\TicketPdfRenderer;

class TicketPurchasedMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, int|float|string> $ticketData */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly array $ticketData,
    ) {}

    public function build(): self
    {
        $mail = $this
            ->subject('Билет на мероприятие: ' . $this->ticketData['event_name'])
            ->view('tickets::email.ticket', ['ticketData' => $this->ticketData]);

        // PDF-билет как вложение. Рендер может упасть (нет Chrome в окружении) —
        // письмо от этого не должно перестать уходить, поэтому ловим и просто
        // отправляем без вложения (см. тот же принцип в `TicketPalette`).
        try {
            $pdf = app(TicketPdfRenderer::class)->render($this->ticket);

            if (is_string($pdf) && $pdf !== '') {
                $mail->attachData($pdf, 'ticket-' . $this->ticket->ticket_number . '.pdf', [
                    'mime' => 'application/pdf',
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('PDF билета не сгенерирован, письмо уходит без вложения.', [
                'ticket_id' => $this->ticket->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return $mail;
    }
}
