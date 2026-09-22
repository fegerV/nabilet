<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

class NewsletterService
{
    /**
     * Отправка новостной рассылки пользователям
     */
    public function sendNewsletter($subject, $content, array $userIds = null)
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
     * Отправка уведомления о покупке билета
     */
    public function sendTicketNotification($booking)
    {
        $ticketGenerator = new \App\Modules\Tickets\Services\TicketGeneratorService();
        $ticketData = $ticketGenerator->generateTicketData($booking);

        try {
            Mail::to($booking->user->email)->send(
                new \App\Modules\Notifications\Mail\TicketPurchasedMail($booking, $ticketData)
            );
            return true;
        } catch (\Exception $e) {
            \Log::error("Failed to send ticket email to {$booking->user->email}: " . $e->getMessage());
            return false;
        }
    }
}
