<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Console;

use App\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Webhooks\Jobs\SendWebhookDeliveryJob;

/**
 * Поднимает отложенные повторы доставки вебхуков.
 *
 * ДЖОБА НЕ РЕТРИТСЯ САМА. У SendWebhookDeliveryJob `tries = 1`: решение о
 * повторе принимает RetryPolicy, который различает «эндпоинт отверг» (4xx —
 * повторять вредно) и «эндпоинт недоступен» (5xx/нет ответа — повторить).
 * Джоба лишь записывает `next_retry_at`; следующую попытку создаёт эта команда.
 *
 * Почему так, а не `tries = 10` в очереди: очередь повторила бы и 4xx, превратив
 * нас в источник нагрузки на чужой сервер, и не дала бы экспоненциальной
 * паузы с джиттером.
 *
 * Запуск — по крону каждую минуту (см. routes/console.php): на шаред-хостинге
 * нет постоянных воркеров, и это единственный способ поднимать повторы.
 */
class RetryPendingWebhookDeliveriesCommand extends Command
{
    /** @var string */
    protected $signature = 'webhooks:retry-pending
                            {--limit=100 : Сколько доставок поднять за один проход}
                            {--dry-run : Показать, что было бы поднято, но не отправлять}';

    /** @var string */
    protected $description = 'Поднимает повторы доставки вебхуков, у которых наступил next_retry_at';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        if ($this->option('dry-run')) {
            $pending = $this->dueDeliveries($limit)->get();
            $this->info(sprintf('К повтору готово доставок: %d.', $pending->count()));

            foreach ($pending as $delivery) {
                $this->line(sprintf(
                    '  #%d %s попытка %d, запланирована на %s',
                    $delivery->id,
                    $delivery->event_name,
                    $delivery->attempt,
                    (string) $delivery->next_retry_at,
                ));
            }

            return self::SUCCESS;
        }

        // Захват записи, снятие next_retry_at и INSERT в database queue — одна
        // транзакция на том же соединении. Иначе два cron-процесса могли бы
        // поднять одну доставку одновременно, а падение между снятием срока и
        // dispatch потеряло бы повтор навсегда. Обязателен database queue;
        // connection `database` пишет в jobs через то же соединение.
        $dispatched = DB::transaction(function () use ($limit): int {
            $pending = $this->dueDeliveries($limit)
                ->lockForUpdate()
                ->get();

            foreach ($pending as $delivery) {
                $delivery->update(['next_retry_at' => null]);

                // Здесь НЕ afterCommit: вставка в jobs должна участвовать в той
                // же транзакции, иначе между commit и INSERT возможна потеря
                // задания. Сам сетевой вызов всё равно делает отдельный worker.
                SendWebhookDeliveryJob::dispatch($delivery->id)
                    ->onConnection('database');
            }

            return $pending->count();
        });

        $this->info(sprintf('Поднято повторов доставки: %d.', $dispatched));

        return self::SUCCESS;
    }

    private function dueDeliveries(int $limit)
    {
        return WebhookDelivery::query()
            ->whereNull('delivered_at')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->limit($limit);
    }
}
