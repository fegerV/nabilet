<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Services;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Orders\Support\OrderEventName;
use Nabilet\Modules\Webhooks\Jobs\SendWebhookDeliveryJob;

/**
 * Исходящие вебхуки: кто подписан на событие и что им отправить.
 *
 * Подписка живёт в таблице `webhooks` (url, secret_encrypted, events_json,
 * active, retry_limit) — схема уже была в спеке, не пришлось изобретать свою.
 * На каждое событие создаётся запись `webhook_deliveries`: доставка — это
 * отдельная сущность с попытками, потому что «мы отправили» и «они получили»
 * разные факты, и поддержке нужно видеть второе.
 *
 * Обсервер вызывает сервис через DispatchOrderWebhookJob ПОСЛЕ коммита заказа:
 * падение fan-out-а не может откатить оплату. Для каждого подписчика запись
 * webhook_deliveries и первая SendWebhookDeliveryJob создаются атомарно друг
 * с другом; если fan-out job повторится, eventId+unique index не создадут
 * вторую доставку уже обработанному подписчику.
 */
class WebhookDispatchService
{
    /** Подписка на все события сразу. */
    public const ALL_EVENTS = '*';

    /**
     * Разослать событие всем подписчикам организации.
     *
     * @param  array<string, mixed>  $data
     * @return int сколько новых доставок создано
     */
    public function dispatch(
        string $eventName,
        array $data,
        int $organizationId,
        ?string $eventId = null,
    ): int {
        // Один стабильный UUID на переход заказа. Job-повторы передают свой
        // прежний eventId, так что доставки уже обработанных подписчиков
        // распознаются по уникальному (webhook_id, delivery_id).
        $eventId ??= (string) Str::uuid();

        $payload = [
            'event' => $eventName,
            'organization_id' => $organizationId,
            'occurred_at' => now()->toIso8601String(),
            'data' => $data,
        ];

        $webhooks = Webhook::query()
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->get();

        $dispatched = 0;

        foreach ($webhooks as $webhook) {
            if (! $this->subscribesTo($webhook, $eventName)) {
                continue;
            }

            // Создание записи доставки и задачи на первый POST атомарны. Иначе
            // сбой между INSERT webhook_deliveries и постановкой job оставил бы
            // строку без next_retry_at — навсегда невидимую sweeper-команде.
            $created = DB::transaction(function () use ($webhook, $eventId, $eventName, $payload): bool {
                $delivery = WebhookDelivery::query()->firstOrCreate(
                    [
                        'webhook_id' => $webhook->id,
                        'delivery_id' => $eventId,
                    ],
                    [
                        'event_name' => $eventName,
                        'payload_json' => $payload,
                        'attempt' => 1,
                        'created_at' => now(),
                    ],
                );

                if (! $delivery->wasRecentlyCreated) {
                    return false;
                }

                // Queue database обязателен для атомарного commit вместе с
                // записью. Очередь не выполняет HTTP здесь — только записывает
                // job в той же БД, worker поднимет её позже.
                SendWebhookDeliveryJob::dispatch($delivery->id)
                    ->onConnection('database');

                return true;
            });

            if ($created) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * Подписан ли вебхук на событие.
     *
     * `events_json` читается и как массив, и как строка: модель `Webhook` не
     * объявляет cast для этой колонки, поэтому из БД может прийти JSON-строка.
     */
    private function subscribesTo(Webhook $webhook, string $eventName): bool
    {
        $events = $webhook->events_json;

        if (is_string($events)) {
            $events = json_decode($events, true);
        }

        if (! is_array($events)) {
            return false;
        }

        return in_array(self::ALL_EVENTS, $events, true)
            || in_array($eventName, $events, true);
    }

    /**
     * События, на которые можно подписаться — для админки.
     *
     * @return list<string>
     */
    public static function availableEvents(): array
    {
        return [self::ALL_EVENTS, ...OrderEventName::all()];
    }
}
