<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Services\TicketPdfRenderer;
use Symfony\Component\Mime\Part\DataPart;

class TicketPurchasedMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, int|float|string> $ticketData */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly array $ticketData,
    ) {}

    /**
     * Content-ID билета-картинки для встраивания в тело письма (inline-CID).
     * Пусто, если растр не сгенерирован (нет Chrome в окружении) — тогда
     * картинка в теле не показывается, письмо уходит как раньше.
     */
    public ?string $ticketImageCid = null;

    public function build(): self
    {
        $mail = $this
            ->subject('Билет на мероприятие: ' . $this->ticketData['event_name'])
            ->view('tickets::email.ticket', ['ticketData' => $this->ticketData]);

        // PDF-билет как вложение. Рендер может упасть (нет Chrome в окружении) —
        // письмо от этого не должно перестать уходить, поэтому ловим и просто
        // отправляем без вложения (см. тот же принцип в `TicketPalette`).
        try {
            $renderer = app(TicketPdfRenderer::class);

            // Inline-CID картинка: полноценный макет билета виден сразу в теле
            // письма, без скачивания вложения. Content-ID остаётся внутри
            // сообщения — подписанный QR при этом не уходит наружу. cid известен
            // заранее (вычислен здесь), поэтому ссылка `cid:…` попадает в
            // разметку, а саму часть добавляет callback при отправке.
            $png = $renderer->renderPng($this->ticket);
            if (is_string($png) && $png !== '') {
                $cid = 'ticket-' . $this->ticket->ticket_number . '-' . $this->ticket->id . '@nabilet';
                $this->ticketImageCid = $cid;

                $this->withSymfonyMessage(function ($message) use ($cid, $png): void {
                    $part = (new DataPart($png, 'ticket-' . $this->ticket->ticket_number . '.png', 'image/png'))
                        ->asInline();
                    $part->setContentId($cid);
                    $message->addPart($part);
                });
            }

            // PDF-билет как вложение (печатная копия).
            $pdf = $renderer->render($this->ticket);

            if (is_string($pdf) && $pdf !== '') {
                $mail->attachData($pdf, 'ticket-' . $this->ticket->ticket_number . '.pdf', [
                    'mime' => 'application/pdf',
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Билет не сгенерирован для письма, отправляем без вложения/картинки.', [
                'ticket_id' => $this->ticket->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return $mail;
    }
}
