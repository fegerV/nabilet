<?php

declare(strict_types=1);

namespace Nabilet\Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Payments\Providers\PaymentProviderInterface;

/**
 * Провайдер-заглушка со сценарием.
 *
 * Нужен там, где поведение самого провайдера — часть тестируемого сценария:
 *
 *  - `transactionLevelAtCall` фиксирует уровень транзакции в момент вызова
 *    `createPayment()`. Это единственный способ доказать, что внешний вызов
 *    ушёл ВНЕ `DB::transaction()` (аудит R-09): по коду видно, где стоит вызов,
 *    но по коду же он и «переезжал» обратно внутрь при рефакторингах;
 *  - `onCreate` позволяет выполнить побочный эффект ровно между «проверили и
 *    решили создавать» и «записали». Так воспроизводится гонка двух
 *    параллельных запросов ДЕТЕРМИНИРОВАННО, без потоков и без `sleep`
 *    (аудит R-02): второй запрос успевает создать свой платёж, пока первый
 *    ещё разговаривает с провайдером.
 */
final class ScriptedPaymentProvider implements PaymentProviderInterface
{
    /** Уровень вложенности транзакции в момент вызова `createPayment()`; -1 — не вызывался. */
    public int $transactionLevelAtCall = -1;

    /** Сколько раз вызывали `createPayment()`. */
    public int $createCalls = 0;

    /** @var (callable(array<string, mixed>): void)|null */
    public $onCreate = null;

    /** Ответ `getPayment()`, если сценарий не задан. */
    public array $remote = ['status' => 'pending', 'provider_data' => []];

    /** @var (callable(string): array<string, mixed>)|null */
    public $onGetPayment = null;

    /** Сколько раз опрашивали статус платежа. */
    public int $getPaymentCalls = 0;

    public function createPayment(array $paymentData): array
    {
        $this->createCalls++;
        $this->transactionLevelAtCall = DB::transactionLevel();

        if ($this->onCreate !== null) {
            ($this->onCreate)($paymentData);
        }

        return [
            'payment_id' => 'scripted_' . Str::ulid(),
            'status' => 'pending',
            'confirmation_url' => 'https://pay.example.test/' . Str::ulid(),
            'provider_data' => [],
        ];
    }

    public function getPayment(string $providerPaymentId): array
    {
        $this->getPaymentCalls++;

        if ($this->onGetPayment !== null) {
            return ($this->onGetPayment)($providerPaymentId);
        }

        return $this->remote;
    }

    public function refund(array $refundData): array
    {
        return [
            'refund_id' => 'scripted_refund_' . Str::ulid(),
            'status' => 'succeeded',
            'amount' => (int) ($refundData['amount'] ?? 0),
            'provider_data' => [],
        ];
    }

    public function handleWebhook(array $payload, string $signature = ''): ?array
    {
        return null;
    }

    public function verifySignature(array $payload, string $signature): bool
    {
        return false;
    }
}
