<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Mail\TicketPurchasedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Services\TicketGeneratorService;
use Throwable;

/**
 * Почтовые хелперы уведомлений.
 *
 * История (аудит лояльности L4): раньше здесь жил `sendNewsletter()`, который
 * читал `users.subscribed_to_newsletter` — колонки, НЕ СУЩЕСТВУЮЩЕЙ ни в одной
 * схеме. Метод не имел вызовов, но остался бы SQL-ошибкой в рантайме при первой
 * же проводке. Удалён: рассылка вернётся в код вместе с таблицей подписчиков
 * в спеке (план лояльности, этап CRM), а не через фантомную колонку.
 */
class NewsletterService
{
    public function __construct(
        private readonly TicketGeneratorService $ticketGenerator,
    ) {}

    /**
     * Отправить письмо по одному уже выпущенному билету.
     * Возвращает false при проблеме транспорта/рендера; оплата и выпуск билета
     * не должны откатываться из-за недоступности почты.
     */
    public function sendTicketNotification(Ticket $ticket): bool
    {
        try {
            $ticketData = $this->ticketGenerator->generateTicketData($ticket);
            Mail::to($ticketData['customer_email'])->send(
                new TicketPurchasedMail($ticket, $ticketData)
            );

            return true;
        } catch (Throwable $e) {
            Log::error('Не удалось отправить письмо с билетом.', [
                'ticket_id' => $ticket->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
