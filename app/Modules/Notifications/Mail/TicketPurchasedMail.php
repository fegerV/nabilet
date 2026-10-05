<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Nabilet\Modules\Tickets\Models\Ticket;

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
        return $this
            ->subject('Билет на мероприятие: ' . $this->ticketData['event_name'])
            ->view('tickets::email.ticket', ['ticketData' => $this->ticketData]);
    }
}
