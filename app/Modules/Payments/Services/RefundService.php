<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Services;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Models\Refund;
use Nabilet\Modules\Payments\Domain\RefundLedger;
use Nabilet\Modules\Payments\Domain\RefundOutcome;
use Nabilet\Modules\Payments\Domain\TransactionType;
use Nabilet\Modules\Payments\Repositories\PaymentRepository;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Modules\Payments\StateMachines\RefundStateMachine;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\StateMachines\TicketStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Доменная логика возвратов (refunds).
 *
 * Вынесена из PaymentService, который совмещал инициацию платежей, обработку
 * вебхуков, выпуск билетов и возвраты — три разные ответственности в одном
 * 600-строчном сервисе. Здесь только жизненный цикл Refund: валидация
 * остатка, создание записи, запрос к провайдеру через реестр, фиксация
 * транзакции. Статусы двигаются явно через RefundStateMachine (раньше
 * константы использовались как «магические строки» без проверки переходов).
 */
final class RefundService
{
    public function __construct(
        private readonly PaymentRepository $repository,
        private readonly PaymentProviderRegistry $providers,
    ) {
    }

    /**
     * Вернуть деньги по успешному платежу (частично или полностью).
     *
     * @return Payment Свежая копия платежа после фиксации возврата.
     */
    public function refund(Payment $payment, ?int $amount = null, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $amount, $reason): Payment {
            // Блокируем строку платежа на время транзакции: без этого два
            // параллельных полных возврата читали одинаковый `totalRefunded = 0`,
            // оба проходили проверку остатка и возвращали деньги дважды.
            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== PaymentStateMachine::SUCCEEDED) {
                throw new DomainRuleViolation(
                    'Can only refund succeeded payments',
                    'PAYMENT_NOT_SUCCEEDED',
                    ['payment_id' => $payment->id, 'status' => $payment->status],
                    422,
                );
            }

            // Сумма уже зачтённых возвратов (неудачные не считаются — их можно
            // повторить тем же RefundStateMachine: failed → processing).
            $totalRefunded = (int) $payment->refunds()
                ->where('status', '!=', RefundStateMachine::FAILED)
                ->sum('amount');

            $currency = $payment->currency ?? 'RUB';
            $paid = Money::of((int) $payment->amount, $currency);

            // «Возвращать уже нечего» — это факт о ПЛАТЕЖЕ, а не арифметика
            // суммы, поэтому у него свой код отказа и своё сообщение. Само
            // решение о сумме принимает RefundLedger (ниже).
            if ($totalRefunded >= $paid->minorUnits()) {
                throw new DomainRuleViolation(
                    'Payment already fully refunded',
                    'PAYMENT_ALREADY_REFUNDED',
                    ['payment_id' => $payment->id],
                    422,
                );
            }

            $refundAmount = $amount ?? ($paid->minorUnits() - $totalRefunded);

            // РЕШЕНИЕ О СУММЕ ПРИНИМАЕТ RefundLedger, а не этот метод.
            //
            // Здесь стояли три собственные проверки — `$refundAmount <= 0`,
            // `$totalRefunded + $refundAmount > $payment->amount` и
            // `$totalRefunded >= $payment->amount`, — то есть та же арифметика,
            // что в `RefundLedger::evaluate()`, но со своими условиями и своими
            // причинами отказа. Две копии одного правила расходятся молча:
            // правку в одной никто не переносит во вторую.
            //
            // Хуже того, `evaluate()` вызывался НИЖЕ, уже после этих проверок,
            // поэтому все его отказы были недостижимы: доменное правило
            // существовало, было покрыто Unit-тестами и при этом ничего не
            // решало — работала ручная копия. Проверка «never refund more than
            // was paid», объявленная в его докблоке, держалась не им.
            $outcome = (new RefundLedger())->evaluate(
                $paid,
                Money::of($totalRefunded, $currency),
                Money::of($refundAmount, $currency),
            );

            if (! $outcome->isAllowed()) {
                throw $this->refusal($outcome, $payment, $refundAmount);
            }

            $machine = RefundStateMachine::make();

            // Запись создаётся сразу в requested — это честное начальное
            // состояние машины; переход requested → processing фиксируется
            // только после того, как провайдер принял запрос.
            /** @var Refund $refund */
            $refund = $payment->refunds()->create([
                'order_id' => $payment->order_id,
                'amount' => $refundAmount,
                'currency' => $payment->currency,
                'reason' => $reason,
                'status' => RefundStateMachine::REQUESTED,
                'provider_refund_id' => null,
            ]);

            try {
                $provider = $this->providers->make((string) ($payment->provider ?: 'yookassa'));

                $providerResponse = $provider->refund([
                    'payment_id' => $payment->provider_payment_id,
                    'amount' => $refundAmount,
                    'currency' => $payment->currency,
                    'reason' => $reason ?? 'Refund',
                    // Детерминированный ключ идемпотентности: провайдер обязан
                    // узнать повторный запрос после сетевого таймаута. Раньше
                    // провайдер генерировал `uniqid()` на каждый вызов, поэтому
                    // повтор после неопределённого исхода воспринимался ЮKassa как
                    // НОВЫЙ возврат — и деньги уходили дважды.
                    'idempotency_key' => 'refund_' . $refund->public_id,
                ]);
            } catch (\Throwable $e) {
                // Провайдер не принял запрос — возврат неудачен, деньги не
                // двигались. Повтор разрешён машиной состояний (failed → processing).
                $this->transition($machine, $refund, RefundStateMachine::FAILED);

                throw new \RuntimeException('Refund processing failed: ' . $e->getMessage(), 0, $e);
            }

            $this->transition($machine, $refund, RefundStateMachine::PROCESSING);

            $refund->update([
                'provider_refund_id' => $providerResponse['refund_id'] ?? null,
            ]);

            $this->repository->addTransaction($payment, [
                'type' => TransactionType::REFUND,
                'amount' => -$refundAmount,
                'status' => PaymentStateMachine::PENDING,
                // Стартовая запись журнала получает provider_event_id ДО прихода
                // ответа провайдера (аудит R-11): UNIQUE (payment_id,
                // provider_event_id) с NULL для этого ключа не защищал бы от
                // дублей. Детерминированный маркер — тот же, что в
                // Idempotence-Key, поэтому повторная инициация того же возврата
                // не может плодить парные транзакции.
                'provider_event_id' => 'refund:' . $refund->public_id,
                'payload_json' => ['refund_id' => $refund->id, 'reason' => $reason],
            ]);

            // A14: заказ обязан отражать возврат. Статус выводится из ДЕНЕГ через
            // RefundLedger (никогда руками): полный возврат → 'refunded', частичный →
            // 'partially_refunded'. Без этого заказ вечно висел 'paid', а билеты
            // оставались сканируемыми (дефект «возвращённый билет проходит check-in»).
            $order = $payment->order;

            // `$outcome` посчитан ВЫШЕ, до записи в журнал, и те же три числа
            // дают тот же ответ — второй вызов `evaluate()` был чистым
            // повторением. Условие `isAllowed()` при этом было всегда истинным:
            // до этой строки отказ уже бросил бы исключение.
            if ($order !== null && $outcome->orderStatus() !== null) {
                // Переход заказа применяется только если он ЛЕГАЛЕН по графу
                // машины состояний. Прямой `update()` раньше переводил в
                // `refunded` даже заказ, который никогда не был `paid`
                // (например, `expired` + автовозврат): статус терял историю,
                // а из `expired`/`cancelled`/`awaiting_payment` такого
                // перехода не существует.
                $orders = OrderStateMachine::make();

                if ($orders->can((string) $order->status, $outcome->orderStatus())) {
                    $order->update(['status' => $outcome->orderStatus()]);

                    // При ПОЛНОМ возврате аннулируем ещё не использованные билеты,
                    // чтобы их QR не проходил check-in (CheckinEvaluator отвергает REFUNDED).
                    if ($outcome->orderStatus() === OrderStateMachine::REFUNDED) {
                        Ticket::where('order_id', $order->id)
                            ->where('status', TicketStateMachine::ISSUED)
                            ->update([
                                'status' => TicketStateMachine::REFUNDED,
                                'refunded_at' => now(),
                            ]);
                    }
                }
            }

            return $payment->fresh();
        });
    }

    /**
     * Доменная причина отказа → код контракта.
     *
     * Единственное место, где словарь `RefundLedger::REASON_*` переводится в
     * `error.code`, который видят клиенты. Это РАЗНЫЕ словари с разной судьбой:
     * причина — часть домена (её можно переименовать, если правило изменится),
     * код — часть контракта (`OrderController` и админка опираются на
     * `REFUND_EXCEEDS_BALANCE`, он же в документации API). Пока перевод стоит
     * здесь, обе стороны можно менять независимо и осознанно.
     */
    private function refusal(RefundOutcome $outcome, Payment $payment, int $amount): DomainRuleViolation
    {
        [$message, $code] = match ($outcome->reason()) {
            RefundLedger::REASON_ZERO_AMOUNT => [
                'Refund amount must be positive',
                'REFUND_AMOUNT_INVALID',
            ],
            RefundLedger::REASON_EXCEEDS_PAID => [
                'Refund amount exceeds remaining payment balance',
                'REFUND_EXCEEDS_BALANCE',
            ],
            // `REASON_NOTHING_PAID` — платёж, по которому нечего возвращать.
            // Штатно сюда не попасть: выше стоит проверка статуса платежа, — но
            // неизвестную причину нельзя выдавать за успех.
            default => [
                'Payment cannot be refunded',
                'PAYMENT_NOT_SUCCEEDED',
            ],
        };

        return new DomainRuleViolation(
            $message,
            $code,
            ['payment_id' => $payment->id, 'amount' => $amount],
            422,
        );
    }

    /**
     * Двигать статус строго через машину состояний: прямой update() обходил бы
     * запрет SUCCEEDED → что-либо и допускал повторный возврат «уже возвращённых» денег.
     */
    private function transition(\Nabilet\Core\StateMachine\StateMachine $machine, Refund $refund, string $to): void
    {
        if (!$machine->can($refund->status, $to)) {
            throw new DomainRuleViolation(
                "Illegal refund transition {$refund->status} → {$to}",
                'REFUND_TRANSITION_DENIED',
                ['refund_id' => $refund->id],
                422,
            );
        }

        $refund->update(['status' => $to]);
    }
}
