<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Nabilet\Core\Support\Money;
use Nabilet\Core\Support\RuText;
use Nabilet\Modules\Notifications\Mail\TemplateMail;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Throwable;

/**
 * Напоминание о незавершённой оплате (брошенный ЗАКАЗ, не корзина).
 *
 * КОГО КАСАЕТСЯ. Покупатель прошёл checkout (имя, адрес — всё введено),
 * заказ в `awaiting_payment`, но платёж не дошёл: закрыл вкладку, передумал
 * рядом с кассой, потерял ссылку. У заказа есть `customer_email` — единственная
 * точка возврата, которая у нас ЧЕСТНО есть: письмо с номером, суммой и
 * ссылкой на оплату (confirmation_url провайдера живёт в `payments.payment_url`).
 *
 * КОГО НЕ КАСАЕТСЯ. Гостевая корзина ДО чекаута: контакта ещё не существует,
 * напоминать некому. Захват адреса до оформления — анти-паттерн,
 * сознательно не делаем.
 *
 * ЗАЧЕМ ФЛАГ `reminder_sent_at` (см. 2026_10_16_000100_add_reminder_columns.php)
 *   Одно письмо на заказ, навсегда. NULL = «не напоминали», ставится только
 *   после фактической отправки — сбой транспорта оставляет NULL, следующий
 *   часовой проход повторит попытку.
 *
 * ПОЧЕМУ ЧАС НАПИСАНИЯ. Меньше — шум для человека, который просто открыл
 * платёжную страницу и вернулся. Больше — бронь успеет истечь, и письмо
 * сообщит о том, чего уже нет (`StaleOrderExpirer` закрывает заказы после
 * TTL + grace).
 */
final class UnpaidOrderReminderSweeper
{
    /** Потолок на письма за проход — как у `OrderReminderSweeper`. */
    private const BATCH_LIMIT = 200;

    /** Как минимум столько заказ должен «полежать» до напоминания. */
    private const MIN_AGE_MINUTES = 60;

    /**
     * Разослать напоминания и вернуть счётчики.
     *
     * @return array{sent: int, failed: int}
     */
    public function remind(): array
    {
        $cutoff = CarbonImmutable::now()
            ->subMinutes(self::MIN_AGE_MINUTES)
            ->toDateTimeString();

        $orders = Order::query()
            ->where('status', OrderStateMachine::AWAITING_PAYMENT)
            ->whereNull('reminder_sent_at')
            ->whereNotNull('customer_email')
            ->where('customer_email', '!=', '')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                Mail::to((string) $order->customer_email)->send($this->mailFor($order));

                $order->forceFill(['reminder_sent_at' => now()])->save();

                $sent++;
            } catch (Throwable $e) {
                // Транспорт не отвечает — флаг НЕ ставим, следующий проход повторит.
                // §66 E2: в журнал — код и краткая причина, а не сырой текст.
                $failed++;

                Log::warning('UnpaidOrderReminder: send failed', [
                    'order_id' => $order->id,
                    'code' => $e instanceof \Nabilet\Core\Errors\AppError ? $e->errorCode : $e::class,
                    'reason' => $e instanceof \Nabilet\Core\Errors\AppError ? $e->getMessage() : substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function mailFor(Order $order): TemplateMail
    {
        // Контекст события/сеанса — плоским запросом: письмо строится для
        // сотен заказов за проход, моделей с отношениями здесь не надо.
        $context = $order->session_id !== null
            ? DB::table('sessions')
                ->leftJoin('events', 'events.id', '=', 'sessions.event_id')
                ->where('sessions.id', $order->session_id)
                ->select('events.title as event_title', 'sessions.starts_at as starts_at')
                ->first()
            : null;

        // Ссылка на оплату — confirmation_url, который вернул провайдер при
        // создании платежа (мы её храним в `payments.payment_url`). У неоплаченного
        // заказа она обычно есть; если нет — письмо остаётся информативным.
        $paymentUrl = DB::table('payments')
            ->where('order_id', $order->id)
            ->whereNotNull('payment_url')
            ->where('payment_url', '!=', '')
            ->orderByDesc('id')
            ->value('payment_url');

        $number = (string) ($order->order_number ?? $order->public_id);

        $lines = [
            'Здравствуйте!',
            sprintf('Заказ %s ещё ждёт оплаты.', $number),
        ];

        if ($context !== null && $context->event_title !== null) {
            $startsAt = $context->starts_at !== null
                ? RuText::stamp($context->starts_at)
                : null;

            $lines[] = $startsAt !== null
                ? sprintf('Мероприятие: %s — %s (мск.).', $context->event_title, $startsAt)
                : sprintf('Мероприятие: %s.', $context->event_title);
        }

        $lines[] = sprintf(
            'Сумма к оплате: %s.',
            Money::of((int) $order->total_amount, (string) ($order->currency ?? 'RUB'))->toDecimal(),
        );

        if (is_string($paymentUrl) && $paymentUrl !== '') {
            $lines[] = 'Завершить оплату по ссылке:';
            $lines[] = $paymentUrl;
        }

        $lines[] = 'Бронь мест освободится автоматически, если оплата не поступит '
            . 'в ближайшее время — после этого место можно будет выбрать заново. '
            . 'Если вы не инициировали эту покупку, просто игнорируйте письмо.';

        $text = implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));

        $html = '<p>' . implode(
            '</p><p>',
            array_map(
                static fn (string $line): string => nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8')),
                explode("\n", $text)
            )
        ) . '</p>';

        return new TemplateMail(
            subjectLine: sprintf('Завершите оплату заказа %s', $number),
            htmlBody: $html,
            textBody: $text,
        );
    }
}
