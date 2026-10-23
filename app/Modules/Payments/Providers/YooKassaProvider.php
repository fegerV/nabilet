<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\ExternalServiceError;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;

/**
 * YooKassa payment provider implementation.
 * 
 * @see https://yookassa.ru/developers/api
 */
class YooKassaProvider implements PaymentProviderInterface
{
    /**
     * Клиентский маршрут подтверждения демо-оплаты.
     *
     * Хранится здесь, а не строкой в двух местах: этот путь строит провайдер, а
     * обслуживает его SPA (`resources/js/router/index.ts`). Разъехавшись, они
     * дают 404 после «оплаты».
     */
    public const DEMO_CONFIRM_PATH = '/checkout/demo-pay';

    public function __construct(
        private readonly string $shopId,
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.yookassa.ru/v3'
    ) {
        // В demo-режиме ключи не нужны: `createPayment()` не ходит в API, а
        // возвращает ссылку на локальный симулятор оплаты.
        //
        // Проверка стояла ДО ветки demo и делала её недостижимой: контейнер
        // создаёт провайдер раньше, чем управление доходит до `createPayment()`,
        // поэтому `.env` с `PAYMENT_DEMO_MODE=true` и пустым `YOOKASSA_SHOP_ID`
        // давал 500 «YooKassa credentials not configured» на КАЖДОМ платеже —
        // демо-режим не работал вообще. Проверено: `POST /api/v1/payments` для
        // заказа без существующего платежа отвечал 500 INTERNAL_ERROR.
        if ($this->isDemoMode()) {
            return;
        }

        if (empty($this->shopId) || empty($this->secretKey)) {
            // `ExternalServiceError`, а не голый `RuntimeException`: клиент должен
            // получить конверт §66 с кодом и внятным текстом, а не
            // `INTERNAL_ERROR` 500 без причины. `retryable: false` — повтор не
            // поможет, пока не заданы ключи.
            throw new ExternalServiceError(
                'yookassa',
                'Платёжный провайдер не настроен: задайте YOOKASSA_SHOP_ID и '
                . 'YOOKASSA_SECRET_KEY либо включите PAYMENT_DEMO_MODE=true.',
                false,
            );
        }
    }

    /** Demo-режим: локальный симулятор вместо обращения к API ЮKassa. */
    private function isDemoMode(): bool
    {
        return (bool) config('nabilet.payment.demo_mode', false);
    }

    /**
     * Хост для демо-ссылки подтверждения.
     *
     * `config('app.url')` в этом проекте — плейсхолдер
     * (`APP_URL=https://tickets.example.com`), поэтому собранная из него ссылка
     * уводила покупателя на несуществующий домен: он нажимал «оплатить» и
     * попадал в никуда. В веб-запросе правильный хост — origin текущего запроса
     * (это ровно тот адрес, по которому открыт сайт), а не конфиг.
     *
     * В консоли (тесты, очереди, artisan) запроса нет — остаётся `app.url`,
     * иначе `request()->root()` вернул бы `http://localhost` из CLI-окружения.
     */
    private function demoOrigin(): string
    {
        if (! app()->runningInConsole()) {
            $root = request()->root();

            if (is_string($root) && $root !== '') {
                return $root;
            }
        }

        return rtrim((string) config('app.url', 'http://127.0.0.1:8000'), '/');
    }

    public function createPayment(array $paymentData): array
        {
            // Demo-режим: нет реальных ключей — не ходим в API, а возвращаем
            // локальный confirmation_url на симулятор оплаты.
            if ($this->isDemoMode()) {
                $paymentId = 'demo_' . (string) Str::ulid()->toBase32();
                // Хеш-адрес, а не путь: витрина — SPA с `createWebHashHistory()`,
                // её отдаёт только `GET /` (routes/web.php). Путь
                // `/checkout/demo-pay` не обслуживается ничем: Laravel такого
                // маршрута не имеет, клиентского — тоже, поэтому покупатель
                // после «оплаты» получал 404 вместо страницы подтверждения.
                $confirmUrl = $this->demoOrigin()
                    . '/#' . self::DEMO_CONFIRM_PATH . '?payment_id=' . $paymentId;

                Log::info('YooKassa demo payment created', [
                    'payment_id' => $paymentId,
                    'order_id' => $paymentData['order_id'] ?? null,
                    'amount' => $paymentData['amount'] ?? null,
                ]);

                return [
                    'payment_id' => $paymentId,
                    'status' => 'pending',
                    'confirmation_url' => $confirmUrl,
                    'confirmation_token' => null,
                    'provider_data' => [
                        'id' => $paymentId,
                        'status' => 'pending',
                        'demo' => true,
                    ],
                ];
            }

            $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Idempotence-Key' => $paymentData['idempotency_key'] ?? uniqid('yk_', true),
        ])
        ->withBasicAuth($this->shopId, $this->secretKey)
        // Таймауты обязательны (аудит производительности 1.4): зависший шлюз
        // не должен удерживать PHP-воркер и фазу-3 транзакции оформления.
        ->timeout(20)
        ->connectTimeout(5)
        ->post("{$this->baseUrl}/payments", [
            'amount' => [
                'value' => number_format($paymentData['amount'] / 100, 2, '.', ''),
                'currency' => $paymentData['currency'],
            ],
            'capture' => true, // Auto-capture payment
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $paymentData['success_url'] ?? config('app.url'),
                'enforce' => false,
            ],
            'description' => $paymentData['description'] ?? "Order #{$paymentData['order_id']}",
            'metadata' => [
                'order_id' => $paymentData['order_id'],
            ],
            'save_payment_method' => false,
        ]);

        if ($response->failed()) {
            Log::error('YooKassa payment creation failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('Failed to create payment with YooKassa');
        }

        $data = $response->json();

        return [
            'payment_id' => $data['id'],
            'status' => $this->mapStatus($data['status']),
            'confirmation_url' => $data['confirmation']['confirmation_url'] ?? null,
            'confirmation_token' => $data['confirmation']['confirmation_token'] ?? null,
            'provider_data' => $data,
        ];
    }

    public function getPayment(string $providerPaymentId): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
        ->withBasicAuth($this->shopId, $this->secretKey)
        ->timeout(20)
        ->connectTimeout(5)
        ->get("{$this->baseUrl}/payments/{$providerPaymentId}");

        if ($response->failed()) {
            Log::error('YooKassa payment fetch failed', [
                'payment_id' => $providerPaymentId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('Failed to fetch payment from YooKassa');
        }

        $data = $response->json();

        return [
            'status' => $this->mapStatus($data['status']),
            'amount' => (int) round((float) $data['amount']['value'] * 100),
            'currency' => $data['amount']['currency'],
            'created_at' => $data['created_at'] ?? null,
            'provider_data' => $data,
        ];
    }

    public function refund(array $refundData): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            // Ключ идемпотентности обязан быть стабильным между повторами одного
            // и того же возврата. Раньше здесь стоял `uniqid('ykr_', true)` —
            // уникальный на каждый вызов, поэтому повтор после таймаута сети
            // ЮKassa воспринимала как НОВЫЙ возврат и списывала деньги дважды.
            // Ключ выводится из `public_id` возврата вызывающим (RefundService).
            'Idempotence-Key' => (string) ($refundData['idempotency_key'] ?? ('ykr_' . $refundData['payment_id'] . '_' . $refundData['amount'])),
        ])
        ->withBasicAuth($this->shopId, $this->secretKey)
        ->timeout(20)
        ->connectTimeout(5)
        ->post("{$this->baseUrl}/refunds", [
            'payment_id' => $refundData['payment_id'],
            'amount' => [
                'value' => number_format($refundData['amount'] / 100, 2, '.', ''),
                'currency' => $refundData['currency'],
            ],
            'description' => $refundData['reason'] ?? 'Refund',
        ]);

        if ($response->failed()) {
            Log::error('YooKassa refund failed', [
                'payment_id' => $refundData['payment_id'],
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('Failed to process refund with YooKassa: ' . ($response->json()['description'] ?? 'Unknown error'));
        }

        $data = $response->json();

        return [
            'refund_id' => $data['id'],
            'status' => $this->mapStatus($data['status']),
            'amount' => (int) round((float) $data['amount']['value'] * 100),
            'provider_data' => $data,
        ];
    }

    public function handleWebhook(array $payload, string $signature = ''): ?array
    {
        // YooKassa sends events via POST with event type in the payload
        // Signature verification is done via HTTPS and optional HMAC
        
        if (!isset($payload['event'], $payload['object'])) {
            Log::warning('Invalid YooKassa webhook payload', ['payload' => $payload]);
            return null;
        }

        $event = $payload['event'];
        $object = $payload['object'];

        // ЛЕВАЯ ЧАСТЬ — события ЮKassa (контракт API), ПРАВАЯ — наши имена
        // событий, на которые диспатчится `PaymentService::processWebhook()`.
        //
        // `payment.canceled` ОБЯЗАН остаться `payment.canceled`. Здесь раньше
        // стояло `'payment.canceled' => 'payment.failed'` — и это не косметика:
        // `PaymentService` разводит эти два случая (аудит A12), помечая
        // отменённый платёж `PaymentStateMachine::CANCELED`, а неудачный —
        // `PaymentStateMachine::FAILED`. Схлопни их один раз, и отменённый
        // платёж становится неотличим от неудачного, причём МОЛЧА: обработчик
        // `payment.canceled` просто перестаёт вызываться, оба кейса дают 200,
        // и тест на это не падает, пока он зовёт `processWebhook()` напрямую.
        //
        // На сегодня этот метод не вызывается ниоткуда: живой путь —
        // `PaymentController::webhook()` → `PaymentService::processWebhook()`
        // с СЫРЫМ payload, то есть мимо нормализатора провайдера. Поэтому
        // ошибка здесь — не действующий дефект, а заряженная ловушка на день,
        // когда вебхуки начнут ходить через провайдера (архитектурно это вернее).
        // Правка сделана заранее, а не по факту поломки.
        $eventType = match ($event) {
            'payment.succeeded' => 'payment.succeeded',
            'payment.waiting_for_capture' => 'payment.waiting_for_capture',
            'payment.canceled' => 'payment.canceled',
            'refund.succeeded' => 'refund.succeeded',
            'refund.failed' => 'refund.failed',
            default => null,
        };

        if ($eventType === null) {
            Log::warning('Unknown YooKassa event type', ['event' => $event]);
            return null;
        }

        return [
            'event_type' => $eventType,
            'payment_id' => $object['id'] ?? ($object['payment_id'] ?? null),
            'event_id' => $object['id'] . '_' . $event . '_' . ($object['created_at'] ?? time()),
            'timestamp' => $object['created_at'] ?? now()->toIso8601String(),
            'data' => $object,
        ];
    }

    public function verifySignature(array $payload, string $signature): bool
    {
        // Ключ — `nabilet.payment.yookassa.webhook_secret`, а не
        // `payments.yookassa_webhook_secret`: файла `config/payments.php` в
        // проекте нет, поэтому прежняя строка ВСЕГДА читала `null`, метод всегда
        // возвращал `true` и подпись не проверялась никогда — fail-open с
        // ложным чувством защиты (аудит R-04b).
        $hmacSecret = config('nabilet.payment.yookassa.webhook_secret');

        if (! is_string($hmacSecret) || $hmacSecret === '') {
            // Fail-closed: если вызывающий явно просит проверить подпись, а
            // секрета нет — проверить нечем. Возврат `true` означал бы «подпись
            // верна» без единой проверки.
            //
            // Основной путь аутентификации уведомлений ЮKassa — allowlist IP в
            // `WebhookAuthenticator`; сюда попадают только те, кто спросил
            // подпись отдельно.
            return false;
        }

        $payloadBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payloadBody === false) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payloadBody, $hmacSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Перевести статус ЮKassa во внутренний (`PaymentStateMachine`).
     *
     * Левая часть `match` — словарь ПРОВАЙДЕРА, его нельзя менять: это контракт
     * API ЮKassa. Правая — наш, и он берётся у владельца константами, а не
     * выписывается здесь: `payments.status` ограничен `ck_payments_status`, и
     * разошедшаяся копия означала бы либо CHECK violation, либо молча
     * принятый статус, которого машина не знает.
     *
     * `canceled` и `failed` провайдера схлопываются в `PaymentStateMachine::FAILED`
     * — это ОСОЗНАННОЕ решение, а не недосмотр, и причина живёт в
     * `ReconcilePaymentsCommand::eventFor()`: по результату опроса для нас исход
     * один — денег нет, холд надо вернуть в продажу. НО: это верно только для
     * пути СВЕРКИ, где статус читается ответом API. Путь ВЕБХУКА различает их
     * (аудит A12: отменённый платёж — отдельное терминальное состояние), и
     * `handleWebhook()` ниже их НЕ схлопывает.
     */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'pending', 'waiting_for_capture' => PaymentStateMachine::PENDING,
            'succeeded' => PaymentStateMachine::SUCCEEDED,
            'canceled', 'failed' => PaymentStateMachine::FAILED,
            default => PaymentStateMachine::PENDING,
        };
    }
}
