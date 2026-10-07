<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Webhooks\Domain\DeliveryAttempt;
use Nabilet\Modules\Webhooks\Domain\DeliveryOutcome;
use Nabilet\Modules\Webhooks\Domain\RetryPolicy;
use Nabilet\Modules\Webhooks\Services\WebhookEndpointGuard;
use Throwable;

/**
 * Однократная попытка доставить событие подписчику.
 *
 * ПОДПИСЬ СЧИТАЕТСЯ ПО ТЕЛУ, КОТОРОЕ РЕАЛЬНО УХОДИТ. Тело сериализуется один
 * раз и отправляется как строка (`withBody`), а не как массив: иначе клиент
 * переупорядочит ключи или изменит экранирование, и подпись, посчитанная до
 * отправки, перестанет совпадать с тем, что получит подписчик. Именно поэтому
 * здесь `json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)` —
 * тот же набор флагов, что и при проверке входящих вебхуков ЮKassa.
 *
 * Ретраями управляет НЕ очередь: у джобы `tries = 1`, а решение принимает
 * RetryPolicy. Причина в том, что политика обязана различать «эндпоинт неверный»
 * (4xx — повторять бессмысленно и вредно) и «эндпоинт лежит» (5xx — повторить).
 * Слепой ретрай очередью превратил бы нас в источник нагрузки на чужой сервер.
 *
 * Повторную попытку поднимает команда `webhooks:retry-pending` по
 * `next_retry_at`, поэтому джоба только пишет следующее время и выходит.
 */
class SendWebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public const TIMEOUT_SECONDS = 10;

    /** response_body — MEDIUMTEXT, но чужой HTML объёмом в мегабайт нам не нужен. */
    private const RESPONSE_BODY_LIMIT = 60000;

    /**
     * @param  float|null  $jitter  детерминированный джиттер для тестов; в проде случайный
     */
    public function __construct(
        public readonly int $deliveryId,
        public readonly ?float $jitter = null,
    ) {}

    public function handle(RetryPolicy $policy, WebhookEndpointGuard $endpointGuard): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->delivered_at !== null) {
            return;
        }

        $webhook = $delivery->webhook;

        if ($webhook === null) {
            return;
        }

        try {
            $secret = Crypt::decryptString((string) $webhook->secret_encrypted);
        } catch (Throwable $e) {
            // Секрет не читается — повтор ничего не изменит, это не «сервер
            // лежит», а сломанная настройка.
            $this->finish($delivery, null, null, 'Секрет вебхука не читается: ' . $e->getMessage(), null);

            return;
        }

        $body = json_encode(
            $delivery->payload_json,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if ($body === false) {
            $this->finish($delivery, null, null, 'Не удалось сериализовать полезную нагрузку.', null);

            return;
        }

        try {
            // Повторная DNS-проверка прямо перед сетевым вызовом: домен мог
            // сменить A/AAAA-запись с момента создания подписки. Перенаправления
            // отключены — повторная валидация URL не имеет смысла, если клиент
            // потом последует 302 на внутренний адрес.
            $resolveEntries = $endpointGuard->resolve((string) $webhook->url);
        } catch (DomainRuleViolation $e) {
            // Изменившийся DNS/внутренний адрес — перманентная ошибка настройки,
            // а не outage подписчика. Повторять её небезопасно и бессмысленно.
            $this->finish($delivery, null, null, $e->getMessage(), null);

            return;
        } catch (Throwable $e) {
            // Ошибка DNS-резолвера сама по себе временная: отдать её политике
            // как «ответа нет», чтобы повторить с backoff. Не отправлять запрос
            // без проверки назначения.
            $resolveEntries = [];
            $statusCode = null;
            $responseBody = null;
            $errorMessage = $e->getMessage();
        }

        if (! isset($errorMessage)) {
            try {
                $request = Http::timeout(self::TIMEOUT_SECONDS)->withoutRedirecting();

            if ($resolveEntries !== [] && ! defined('CURLOPT_RESOLVE')) {
                // DNS-имя без возможности pin-ить резолвинг снова открыло бы
                // TOCTOU на rebinding: между guard и connect адрес может стать
                // приватным. Fail closed — доменные вебхуки требуют ext-curl.
                $this->finish(
                    $delivery,
                    null,
                    null,
                    'Для безопасной доставки webhook по DNS-имени требуется PHP ext-curl.',
                    null,
                );

                return;
            }

            if ($resolveEntries !== []) {
                $request = $request->withOptions([
                    'curl' => [constant('CURLOPT_RESOLVE') => $resolveEntries],
                ]);
            }

            $response = $request->withHeaders([
                    'X-Nabilet-Event' => $delivery->event_name,
                    'X-Nabilet-Delivery' => $delivery->delivery_id,
                    'X-Nabilet-Signature' => hash_hmac('sha256', $body, $secret),
                ])
                    ->withBody($body, 'application/json')
                    ->post($webhook->url);

                $statusCode = $response->status();
                $responseBody = $response->body();
                $errorMessage = null;
            } catch (Throwable $e) {
                // Нет ответа вообще: DNS, TLS, таймаут, соединение сброшено.
                // statusCode = null — RetryPolicy считает это повторяемым.
                $statusCode = null;
                $responseBody = null;
                $errorMessage = $e->getMessage();
            }
        }

        $outcome = $policy->evaluate(new DeliveryAttempt(
            attempt: (int) $delivery->attempt,
            statusCode: $statusCode,
            occurredAt: new DateTimeImmutable(),
            retryLimit: (int) ($webhook->retry_limit ?? DeliveryAttempt::DEFAULT_RETRY_LIMIT),
            jitter: $this->jitter ?? random_int(0, 1_000_000) / 1_000_000,
        ));

        $this->finish(
            delivery: $delivery,
            statusCode: $statusCode,
            responseBody: $responseBody,
            errorMessage: $errorMessage ?? $this->stopReason($outcome),
            nextRetryAt: $outcome->nextRetryAt(),
            delivered: $outcome->isDelivered(),
            incrementAttempt: $outcome->shouldRetry(),
            abandoned: $outcome->isAbandoned(),
        );
    }

    private function finish(
        WebhookDelivery $delivery,
        ?int $statusCode,
        ?string $responseBody,
        ?string $errorMessage,
        ?DateTimeImmutable $nextRetryAt,
        bool $delivered = false,
        bool $incrementAttempt = false,
        bool $abandoned = false,
    ): void {
        // error_message очищается только при УСПЕШНОЙ доставке: иначе повторный
        // удачный POST оставил бы на записи текст ошибки прошлой попытки.
        // Для abandoned причина ОБЯЗАНА сохраниться — это единственное место,
        // где поддержка видит, ПОЧЕМУ доставка прекращена (4xx против исчерпания
        // лимита). Обнулять её вместе с delivered значило бы снова сделать
        // исход неотличимым, ради чего и заведены два раздельных reason.
        $update = [
            'status_code' => $statusCode,
            'response_body' => $responseBody === null
                ? null
                : Str::limit($responseBody, self::RESPONSE_BODY_LIMIT, ''),
            'error_message' => $delivered ? null : $errorMessage,
            'next_retry_at' => $nextRetryAt,
        ];

        if ($delivered) {
            $update['delivered_at'] = now();
        }

        if ($incrementAttempt) {
            $update['attempt'] = $delivery->attempt + 1;
        }

        $delivery->update($update);
    }

    private function stopReason(DeliveryOutcome $outcome): ?string
    {
        if ($outcome->isDelivered()) {
            return null;
        }

        return match ($outcome->reason()) {
            DeliveryOutcome::REASON_PERMANENT_FAILURE =>
                'Эндпоинт отверг доставку без шанса на успех (4xx) — повторы прекращены.',
            DeliveryOutcome::REASON_RETRY_LIMIT_REACHED =>
                'Исчерпан лимит попыток — повторы прекращены.',
            default => null,
        };
    }
}
