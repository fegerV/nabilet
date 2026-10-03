<?php

namespace App\Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TicketPurchasedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $ticketData;
    public $booking;

    /**
     * Create a new message instance.
     */
    public function __construct($booking, $ticketData)
    {
        $this->booking = $booking;
        $this->ticketData = $ticketData;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject("Билет на событие: {$this->ticketData['event_name']}")
                    ->view('tickets::email.ticket')
                    ->attachData(
                        \PDF::loadView('tickets::email.ticket', ['ticketData' => $this->ticketData])->output(),
                        "ticket_{$this->booking->id}.pdf",
                        [
                            'mime' => 'application/pdf',
                        ]
                    );
    }
}
