<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Services\PaymentProviderRegistry;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Throwable;

/**
 * Сверка зависших платежей с провайдером (аудит R-10).
 *
 * ДЕФЕКТ. Потерянное уведомление провайдера означало «деньги есть — заказа
 * нет» НАВСЕГДА. `YooKassaProvider::getPayment()` был реализован и покрыт
 * контрактом, но не вызывался ниоткуда; команды сверки не существовало. Платёж
 * оставался `pending`, заказ — `awaiting_payment`, холд истекал, места уходили в
 * перепродажу, а покупатель с оплаченным (по данным банка) заказом не получал
 * ни билета, ни возврата. Никакой автоматики, которая это заметит, в проекте не
 * было — только ручной разбор по жалобе.
 *
 * ЧТО ДЕЛАЕТ КОМАНДА. Берёт платежи, которые слишком долго висят в
 * `pending`/`waiting_for_capture`, опрашивает провайдера и, если деньги
 * фактически получены, проводит результат через ТОТ ЖЕ путь, что и вебхук —
 * `PaymentService::processWebhook()`. Отдельная «упрощённая» запись статуса
 * здесь была бы вторым путём к деньгам, не покрытым ни сверкой суммы и валюты,
 * ни запретом воскрешения терминального заказа, ни автовозвратом при истёкшем
 * холде.
 *
 * ЧЕГО КОМАНДА НЕ ДЕЛАЕТ. Не помечает платёж провалом, когда провайдер
 * недоступен: исход неизвестен, деньги могли уйти. Такой платёж остаётся как
 * есть и попадает в следующий проход.
 *
 * Запуск — по расписанию каждые 10 минут (см. `routes/console.php`).
 */
class ReconcilePaymentsCommand extends Command
{
    /** @var string */
    protected $signature = 'payments:reconcile
                            {--minutes=15 : Возраст платежа, после которого его пора сверить}
                            {--limit=100 : Сколько платежей проверять за проход}
                            {--stale-webhooks=10 : Порог в минутах для отчёта о необработанных вебхуках}
                            {--dry-run : Показать, что было бы сделано, но не менять данные}';

    /** @var string */
    protected $description = 'Сверяет зависшие платежи с провайдером и сообщает о потерянных вебхуках';

    public function handle(PaymentProviderRegistry $providers, PaymentService $payments): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $stuck = $this->stuckPayments();

        if ($stuck->isEmpty()) {
            $this->info('Зависших платежей нет.');
        }

        $checked = 0;
        $settled = 0;
        $unreachable = 0;

        foreach ($stuck as $payment) {
            $checked++;

            try {
                $remote = $providers->make((string) $payment->provider)
                    ->getPayment((string) $payment->provider_payment_id);
            } catch (Throwable $e) {
                // Недоступность провайдера — НЕ повод объявить платёж неудачным.
                // Исход неизвестен, и «failed» здесь означал бы освобождение
                // места, которое, возможно, уже оплачено.
                $unreachable++;
                $this->warn(sprintf('  #%d: провайдер недоступен — %s', $payment->id, $e->getMessage()));

                continue;
            }

            $event = $this->eventFor($remote);

            if ($event === null) {
                // Провайдер тоже считает платёж незавершённым — ждём дальше.
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('  #%d → %s (dry-run)', $payment->id, $event));

                continue;
            }

            $payments->processWebhook(
                (string) $payment->provider,
                $this->webhookPayload($payment, $remote, $event),
            );

            $settled++;
            $this->info(sprintf('  #%d → %s', $payment->id, $event));
        }

        if ($checked > 0) {
            $this->info(sprintf(
                'Проверено платежей: %d, проведено: %d, провайдер недоступен: %d.',
                $checked,
                $settled,
                $unreachable,
            ));
        }

        $this->reportStaleWebhookEvents();

        return self::SUCCESS;
    }

    /**
     * Платежи, которые висят слишком долго и ещё не завершены.
     *
     * `provider_payment_id IS NOT NULL` обязателен: без него нечего спрашивать у
     * провайдера, и такой платёж — это брошенная попытка, а не потерянное
     * уведомление.
     *
     * @return Collection<int, Payment>
     */
    private function stuckPayments(): Collection
    {
        return Payment::query()
            ->whereIn('status', [PaymentStateMachine::PENDING, PaymentStateMachine::WAITING_FOR_CAPTURE])
            ->whereNotNull('provider_payment_id')
            ->where('created_at', '<=', now()->subMinutes($this->ageMinutes()))
            ->orderBy('created_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
    }

    /**
     * Событие для результата опроса, или `null`, если платёж ещё не завершён.
     *
     * Статус приходит в ВНУТРЕННЕЙ терминологии (`PaymentStateMachine`):
     * провайдер переводит свой словарь сам (`YooKassaProvider::mapStatus()`).
     * `failed` покрывает и `canceled`, и `failed` — для нас исход один: денег
     * нет, холд надо вернуть в продажу.
     *
     * @param  array<string, mixed>  $remote
     */
    private function eventFor(array $remote): ?string
    {
        return match ((string) ($remote['status'] ?? '')) {
            PaymentStateMachine::SUCCEEDED => 'payment.succeeded',
            PaymentStateMachine::FAILED => 'payment.failed',
            default => null,
        };
    }

    /**
     * Уведомление, синтезированное из ответа провайдера.
     *
     * Форма повторяет то, что шлёт ЮKassa, потому что `processWebhook()` читает
     * именно её: `object.id` (идентификатор платежа у провайдера) и
     * `object.amount.value` (сумма).
     *
     * Сумма переводится из минорных единиц в десятичную СТРОКУ. Это не
     * косметика: сверка внутри `processWebhook()` разбирает сумму через
     * `Money::fromDecimal()`, а целое `5000000` она прочитала бы как пять
     * миллионов рублей — то есть корректный платёж был бы отвергнут как
     * недоплата и ушёл бы в автовозврат.
     *
     * `event_id` детерминирован. Повторный проход по тому же платежу попадёт в
     * UNIQUE `webhook_events` и не проведёт платёж второй раз — идемпотентность
     * обеспечена схемой, а не аккуратностью вызывающего.
     *
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function webhookPayload(Payment $payment, array $remote, string $event): array
    {
        $currency = (string) ($remote['currency'] ?? $payment->currency ?? 'RUB');

        return [
            'event' => $event,
            'object' => [
                'id' => (string) $payment->provider_payment_id,
                'status' => (string) ($remote['status'] ?? ''),
                'amount' => [
                    'value' => Money::of((int) ($remote['amount'] ?? $payment->amount), $currency)->toDecimal(),
                    'currency' => $currency,
                ],
                'created_at' => (string) ($remote['created_at'] ?? now()->toIso8601String()),
            ],
            'event_id' => sprintf('reconcile:%s:%s', (string) $payment->provider_payment_id, $event),
        ];
    }

    /**
     * Отчёт о необработанных уведомлениях.
     *
     * `webhook_events.processed_at IS NULL` старше порога — это либо уведомление,
     * на котором упал обработчик (провайдер будет повторять), либо потерянное.
     * Раньше на это не смотрел НИКТО: таблица росла, а «деньги есть — заказа
     * нет» выглядело как обычная строка в базе.
     */
    private function reportStaleWebhookEvents(): void
    {
        $thresholdMinutes = max(1, (int) $this->option('stale-webhooks'));

        $stale = DB::table('webhook_events')
            ->whereNull('processed_at')
            ->where('created_at', '<=', now()->subMinutes($thresholdMinutes))
            ->count();

        if ($stale === 0) {
            return;
        }

        $this->warn(sprintf(
            'ВНИМАНИЕ: %d уведомлений провайдера старше %d мин. не обработаны '
            . '(webhook_events.processed_at IS NULL).',
            $stale,
            $thresholdMinutes,
        ));
    }

    private function ageMinutes(): int
    {
        return max(1, (int) $this->option('minutes'));
    }
}
