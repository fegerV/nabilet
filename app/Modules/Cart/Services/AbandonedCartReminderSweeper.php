<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Nabilet\Modules\Notifications\Mail\TemplateMail;
use Throwable;

/**
 * Напоминание о брошенной корзине АВТОРИЗОВАННОГО покупателя.
 *
 * КОГО КАСАЕТСЯ. Только корзины с `carts.user_id`: email живёт в аккаунте.
 * Гостевая корзина контакта не имеет — reminder по ней физически невозможен
 * (захват адреса до оформления сознательно не делаем).
 *
 * КОГДА. TTL корзины — 15 минут, и свипер инвентаря заберёт места сразу после
 * grace. Почасовой прогон здесь бессмыслен: к его тику корзина уже мертва.
 * Поэтому команда планируется РАЗ В ПЯТЬ МИНУТ и напоминает корзинам, чей
 * `expires_at` наступает в ближайшие 5 минут: письмо приходит, пока бронь ещё
 * жива, и человек успевает продлить выбор.
 *
 * ЗАЧЕМ ФЛАГ `carts.reminder_sent_at`. Одно письмо на корзину; продление
 * (`POST /cart/extend`) флаг не сбрасывает — повторные продления не порождают
 * повторные письма. NULL = «не напоминали».
 */
final class AbandonedCartReminderSweeper
{
    private const BATCH_LIMIT = 200;

    /** Окно «скоро истечёт»: от текущего момента до +5 минут. */
    private const REMIND_BEFORE_MINUTES = 5;

    /**
     * Разослать напоминания и вернуть счётчики.
     *
     * @return array{sent: int, failed: int}
     */
    public function remind(): array
    {
        $now = CarbonImmutable::now();

        $carts = DB::table('carts')
            ->join('users', 'users.id', '=', 'carts.user_id')
            ->where('carts.status', 'active')
            ->whereNull('carts.reminder_sent_at')
            ->where('carts.expires_at', '>=', $now->toDateTimeString())
            ->where('carts.expires_at', '<=', $now->addMinutes(self::REMIND_BEFORE_MINUTES)->toDateTimeString())
            ->whereExists(static function ($query): void {
                // Пустую корзину не напоминаем: напоминание без содержимого
                // читается как спам.
                $query->select(DB::raw(1))
                    ->from('cart_items')
                    ->whereColumn('cart_items.cart_id', 'carts.id');
            })
            ->orderBy('carts.id')
            ->limit(self::BATCH_LIMIT)
            ->get([
                'carts.id',
                'carts.session_id',
                'carts.expires_at',
                'users.email',
            ]);

        $sent = 0;
        $failed = 0;

        foreach ($carts as $cart) {
            try {
                Mail::to((string) $cart->email)->send($this->mailFor($cart));

                DB::table('carts')
                    ->where('id', $cart->id)
                    ->update([
                        'reminder_sent_at' => $now->toDateTimeString(),
                        'updated_at' => $now->toDateTimeString(),
                    ]);

                $sent++;
            } catch (Throwable $e) {
                $failed++;

                // §66 E2: в журнал — код и краткая причина, а не сырой текст.
                Log::warning('AbandonedCartReminder: send failed', [
                    'cart_id' => $cart->id,
                    'code' => $e instanceof \Nabilet\Core\Errors\AppError ? $e->errorCode : $e::class,
                    'reason' => $e instanceof \Nabilet\Core\Errors\AppError ? $e->getMessage() : substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'debug_window_low' => $deadlineLow, 'debug_window_high' => $deadlineHigh];
    }

    private function mailFor(object $cart): TemplateMail
    {
        $context = DB::table('sessions')
            ->leftJoin('events', 'events.id', '=', 'sessions.event_id')
            ->where('sessions.id', $cart->session_id)
            ->select('events.title as event_title', 'sessions.starts_at as starts_at')
            ->first();

        $expiresAt = CarbonImmutable::parse($cart->expires_at)->format('H:i');

        $lines = [
            'Здравствуйте!',
        ];

        if ($context !== null && $context->event_title !== null) {
            $startsAt = $context->starts_at !== null
                ? CarbonImmutable::parse($context->starts_at)->format('d.m.Y H:i')
                : null;

            $lines[] = sprintf(
                'Ваши места на «%s»%s ещё удержаны за вашей корзиной.',
                $context->event_title,
                $startsAt !== null ? ' (' . $startsAt . ', мск.)' : '',
            );
        } else {
            $lines[] = 'Ваши места ещё удержаны за вашей корзиной.';
        }

        $lines[] = sprintf(
            'Бронь истекает в %s — после этого места вернутся в продажу, '
            . 'и выбор придётся повторить. Вернитесь к оформлению, чтобы не потерять места.',
            $expiresAt,
        );

        $text = implode("\n", $lines);

        $html = '<p>' . implode('</p><p>', array_map(
            static fn (string $line): string => nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8')),
            explode("\n", $text)
        )) . '</p>';

        return new TemplateMail(
            subjectLine: 'Ваши места ждут — бронь истекает',
            htmlBody: $html,
            textBody: $text,
        );
    }
}
