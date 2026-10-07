<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Nabilet\Modules\Webhooks\Services\WebhookDispatchService;

/**
 * Разослать событие заказа подписчикам ПОСЛЕ коммита заказа.
 *
 * Обсервер не выполняет SQL-операции вебхуков синхронно: ошибка в настройке
 * стороннего endpoint или временная проблема БД доставок не должна откатывать
 * уже принятую оплату. Джоба ставится `afterCommit()`; служебная запись,
 * payload и задачи конкретным адресатам появляются уже вне транзакции покупки.
 *
 * `eventId` создаётся в обсервере и сериализуется вместе с заданием. Если job
 * автоматически повторится после частичного сбоя при fan-out, WebhookDispatchService
 * переиспользует этот id как `delivery_id`, а уникальный индекс `(webhook_id,
 * delivery_id)` не позволяет повторно создать отправку подписчику, которому
 * задание уже успело пройти.
 */
class DispatchOrderWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** Интервалы: минута, пять минут, пятнадцать минут, полчаса. */
    public array $backoff = [60, 300, 900, 1800];

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $eventName,
        public readonly array $payload,
        public readonly int $organizationId,
        public readonly string $eventId,
    ) {}

    public function handle(WebhookDispatchService $webhooks): void
    {
        $webhooks->dispatch(
            eventName: $this->eventName,
            data: $this->payload,
            organizationId: $this->organizationId,
            eventId: $this->eventId,
        );
    }
}
