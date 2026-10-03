<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Services;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Models\Refund;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Modules\Payments\StateMachines\RefundStateMachine;
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

            if ($totalRefunded >= (int) $payment->amount) {
                throw new DomainRuleViolation(
                    'Payment already fully refunded',
                    'PAYMENT_ALREADY_REFUNDED',
                    ['payment_id' => $payment->id],
                    422,
                );
            }

            $refundAmount = $amount ?? ((int) $payment->amount - $totalRefunded);

            if ($refundAmount <= 0) {
                throw new DomainRuleViolation(
                    'Refund amount must be positive',
                    'REFUND_AMOUNT_INVALID',
                    ['payment_id' => $payment->id, 'amount' => $refundAmount],
                    422,
                );
            }

            if ($totalRefunded + $refundAmount > (int) $payment->amount) {
                throw new DomainRuleViolation(
                    'Refund amount exceeds remaining payment balance',
                    'REFUND_EXCEEDS_BALANCE',
                    ['payment_id' => $payment->id, 'amount' => $refundAmount],
                    422,
                );
            }

            $machine = RefundStateMachine::make();

            // Запись создаётся сразу в requested — это честное начальное
            // состояние машины; переход requested → processing фиксируется
            // только после того, как провайдер принял запрос.
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
                'type' => 'refund',
                'amount' => -$refundAmount,
                'status' => 'pending',
                'payload_json' => ['refund_id' => $refund->id, 'reason' => $reason],
            ]);

            return $payment->fresh();
        });
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
