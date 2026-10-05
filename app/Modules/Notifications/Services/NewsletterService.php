<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Mail\TicketPurchasedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Services\TicketGeneratorService;
use Throwable;

class NewsletterService
{
    public function __construct(
        private readonly TicketGeneratorService $ticketGenerator,
    ) {}

    /**
     * Отправка новостной рассылки пользователям
     */
    public function sendNewsletter($subject, $content, ?array $userIds = null)
    {
        $query = User::where('subscribed_to_newsletter', true);
        
        if ($userIds) {
            $query->whereIn('id', $userIds);
        }

        $users = $query->get();
        $sentCount = 0;
        $failedCount = 0;

        foreach ($users as $user) {
            try {
                Mail::raw($content, function ($message) use ($user, $subject) {
                    $message->to($user->email)
                            ->subject($subject);
                });
                $sentCount++;
            } catch (\Exception $e) {
                \Log::error("Failed to send newsletter to {$user->email}: " . $e->getMessage());
                $failedCount++;
            }
        }

        return [
            'sent' => $sentCount,
            'failed' => $failedCount,
            'total' => $users->count(),
        ];
    }

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
