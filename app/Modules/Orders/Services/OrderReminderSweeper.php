<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nabilet\Modules\Notifications\Jobs\SendOrderNotificationJob;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Sessions\StateMachines\SessionStateMachine;
use Throwable;

/**
 * Напоминание покупателю за сутки до мероприятия.
 *
 * ПОЧЕМУ СВИП, А НЕ ОБСЕРВЕР. `OrderObserver` ловит смену статуса заказа. Здесь
 * её нет: заказ, купленный месяц назад, в момент напоминания не меняется никто.
 * Событие, которое надо поймать, — наступление времени, а такое умеет только
 * периодический проход по расписанию. Это тот же приём, что у
 * `webhooks:retry-pending`: команда по крону поднимает то, чей срок пришёл.
 *
 * ПОЧЕМУ ЧАСОВОЕ ОКНО, А НЕ «РОВНО 24 ЧАСА». Сравнение через равенство
 * (`starts_at = now + 24h`) не сработало бы никогда: крон приходит с точностью
 * до минуты, а между тиками проходит ненулевое время. Свип берёт ИНТЕРВАЛ
 * `[now + 24h, now + 25h)`, поэтому каждый сеанс попадает в него ровно за сутки
 * — и ровно один раз, потому что интервал шириной в час перекрывает минутный
 * шаг планировщика с запасом.
 *
 * ИДЕМПОТЕНТНОСТЬ. Двойная, и это не избыточность:
 *   1. `order_reminders.order_id` UNIQUE — вставка в одной транзакции с
 *      постановкой письма в очередь. Два процесса, взявшие один заказ, дадут
 *      гонку на INSERT, а не два письма. SELECT-перед-INSERT этой гарантии не
 *      даёт: между проверкой и вставкой влезает второй процесс.
 *   2. Свип дополнительно не берёт заказы, у которых запись уже есть, — иначе
 *      каждый тик заново тянул бы из БД всю историю и спотыкался на UNIQUE
 *      вместо того, чтобы просто не выбирать их.
 *
 * Заказы без `session_id` пропускаются: у заказа нет даты, к которой можно
 * привязать «завтра». Так же пропускаются сеансы отменённые, закрытые и
 * завершённые — напоминать о том, чего не будет, значит звать человека зря и
 * подрывать доверие к остальным письмам.
 */
final class OrderReminderSweeper
{
    /**
     * Ширина окна в часах. Час, а не минута: крон приходит раз в час, и более
     * узкое окно могло бы проскочить между тиками (крон сдвигается, если
     * предыдущий процесс не успел выйти).
     */
    private const WINDOW_HOURS = 1;

    /**
     * Потолок на число писем за один проход. Свип идёт по расписанию и после
     * сбоя может наткнуться на накопленную очередь; ограничение не даёт одному
     * запуску вычитать всю `orders` и поставить тысячи писем разом. Остаток
     * заберёт следующий тик.
     */
    private const BATCH_LIMIT = 200;

    /**
     * Статусы сеанса, по которым напоминание НЕ отправляется.
     *
     * `closed` — продажи остановлены, но выступление ещё впереди: напоминание по
     * нему осмысленно, поэтому он здесь НЕ исключён. Исключены только те, где
     * мероприятия не будет: отменённое и завершённое (второе — потому что
     * напоминать задним числом поздно, а `completed` мог выставиться досрочно
     * при ручной правке расписания).
     */
    private const SKIPPED_SESSION_STATUSES = [
        SessionStateMachine::CANCELLED,
        SessionStateMachine::COMPLETED,
    ];

    /**
     * Разослать напоминания и вернуть число поставленных в очередь писем.
     *
     * @param  bool  $dryRun  только показать, кому уйдёт письмо, ничего не отправляя
     * @param  int|null  $limit  переопределить потолок батча (для тестов и ручного разбора)
     * @return array{sent: int, skipped: int, orders: list<int>}
     */
    public function sweep(bool $dryRun = false, ?int $limit = null): array
    {
        $limit ??= self::BATCH_LIMIT;

        // Единственный источник «сейчас»: и границы окна, и проверка «уже
        // напоминали» считаются от него. Два отдельных now() в одном проходе
        // могли бы разойтись на границе окна и дать заказ дважды или ни разу.
        $now = CarbonImmutable::now();
        $windowStart = $now->addHours(24)->toDateTimeString();
        $windowEnd = $now->addHours(24 + self::WINDOW_HOURS)->toDateTimeString();

        $candidates = $this->candidates($windowStart, $windowEnd, $limit);

        $sent = 0;
        $skipped = 0;
        $orderIds = [];

        foreach ($candidates as $orderId) {
            try {
                $queued = $dryRun
                    ? $this->describe($orderId)
                    : $this->queueReminder($orderId, $now);

                if ($queued) {
                    $sent++;
                    $orderIds[] = $orderId;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                // Один сбойный заказ не должен останавливать рассылку остальным:
                // свип идёт по расписанию, и упавший проход отложил бы напоминания
                // всем, кто шёл следом. Пишем в лог и продолжаем.
                $skipped++;
                Log::error('Напоминание за сутки: заказ пропущен из-за ошибки.', [
                    'order_id' => $orderId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'orders' => $orderIds];
    }

    /**
     * Кандидаты: оплаченные заказы, чей сеанс начинается ровно через сутки.
     *
     * @return list<int>
     */
    private function candidates(string $windowStart, string $windowEnd, int $limit): array
    {
        return DB::table('orders')
            ->join('sessions', 'sessions.id', '=', 'orders.session_id')
            // Журнал напоминаний — LEFT JOIN: строки нет у подавляющего
            // большинства заказов, и именно их мы и хотим получить.
            ->leftJoin('order_reminders', 'order_reminders.order_id', '=', 'orders.id')
            ->where('orders.status', OrderStateMachine::PAID)
            ->whereNotNull('orders.customer_email')
            ->whereNull('order_reminders.id')
            ->whereNotNull('sessions.starts_at')
            ->where('sessions.starts_at', '>=', $windowStart)
            ->where('sessions.starts_at', '<', $windowEnd)
            ->whereNotIn('sessions.status', self::SKIPPED_SESSION_STATUSES)
            ->orderBy('orders.id')
            ->limit($limit)
            ->pluck('orders.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Поставить письмо в очередь и зафиксировать факт в журнале — одной
     * транзакцией.
     *
     * Запись в `order_reminders` и `dispatch` соседствуют намеренно: если бы
     * запись коммитилась отдельно, падение между двумя шагами оставило бы
     * журнал без письма — и напоминание не ушло бы никогда, потому что свип
     * перестал бы видеть заказ. Обратный порядок (письмо без записи) дал бы
     * дубли при каждом следующем тике. Транзакция закрывает оба окна.
     *
     * Здесь НЕ `afterCommit()`: `dispatch` на connection `database` пишет в
     * `jobs` через то же соединение, и вставка обязана быть частью транзакции —
     * иначе между commit и INSERT возможна потеря задания. Сетевой вызов всё
     * равно делает отдельный воркер (см. `queue:work` в routes/console.php).
     */
    private function queueReminder(int $orderId, CarbonImmutable $now): bool
    {
        return (bool) DB::transaction(function () use ($orderId, $now): bool {
            $order = Order::query()
                ->lockForUpdate()
                ->find($orderId);

            // Финальная проверка внутри транзакции: между выборкой кандидатов и
            // этим моментом заказ могли отменить или вернуть.
            if ($order === null || $order->status !== OrderStateMachine::PAID) {
                return false;
            }

            if (trim((string) $order->customer_email) === '') {
                return false;
            }

            // Повторная проверка журнала под блокировкой: защита от второго
            // процесса, который прошёл LEFT JOIN до нашей вставки.
            $alreadyPresent = DB::table('order_reminders')
                ->where('order_id', $orderId)
                ->exists();

            if ($alreadyPresent) {
                return false;
            }

            DB::table('order_reminders')->insert([
                'public_id' => (string) Str::ulid()->toBase32(),
                'order_id' => $order->id,
                'organization_id' => (int) $order->organization_id,
                'session_id' => (int) $order->session_id,
                'created_at' => $now->toDateTimeString(),
                // NULL = «письмо поставлено в очередь, отправка ещё не
                // подтверждена». Так и задумано: строку пишем до постановки в
                // очередь, а подтверждает её `notifications.status = sent` при
                // успешной отправке. Проставлять здесь время значило бы
                // утверждать, что письмо ушло, ещё до попытки его отправить.
                'sent_at' => null,
            ]);

            // `dispatch()` со СКОБКАМИ и явной связкой соединения: вариант
            // `::forReminder(...)->onConnection('database')` без вызова работал бы
            // через деструктор PendingDispatch, а он выполняется позже, чем
            // закрывается транзакция, — и в тестах джоба не попадала в Bus::fake().
            // Явный вызов кладёт задание в очередь внутри транзакции, что нам и
            // нужно (см. докблок выше).
            dispatch(SendOrderNotificationJob::forReminder($order->id))
                ->onConnection('database');

            return true;
        });
    }

    /**
     * Проверка кандидата без побочных эффектов — для `--dry-run`.
     *
     * Возвращает true, если письмо БЫЛО БЫ отправлено. Повторяет условия
     * `queueReminder`, но ничего не пишет и не блокирует: dry-run нужен, чтобы
     * глазами проверить выборку перед первой боевой рассылкой.
     */
    private function describe(int $orderId): bool
    {
        $order = Order::query()->find($orderId);

        if ($order === null || $order->status !== OrderStateMachine::PAID) {
            return false;
        }

        if (trim((string) $order->customer_email) === '') {
            return false;
        }

        return ! DB::table('order_reminders')->where('order_id', $orderId)->exists();
    }
}
