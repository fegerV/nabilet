<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Nabilet\Modules\Loyalty\Models\Raffle;
use Nabilet\Modules\Loyalty\Services\RaffleService;

/**
 * Добрать билеты для оплаченных заказов окна при активации розыгрыша.
 *
 * ПОЧЕМУ В ОЧЕРЕДИ, А НЕ В ЗАПРОСЕ АКТИВАЦИИ
 *   Розыгрыш «за всё время» перебирает всю таблицу заказов: на живой базе
 *   это десятки секунд и больше, а организатор нажал кнопку и ждёт ответ.
 *   Поэтому `activate()` возвращает сразу, а состав участников доезжает
 *   фоново — как и письма, и вебхуки в этом проекте.
 *
 * ПОЧЕМУ ПОВТОР БЕЗОПАСЕН
 *   Backfill идемпотентен по схеме (`UNIQUE (raffle_id, order_id)`), поэтому
 *   повтор джобы после сбоя и повторная активация после паузы
 *   (`active → draft → active`) не дублируют участников: они добирают
 *   ровно те заказы, которых ещё нет.
 *
 * ПОЧЕМУ СТАТУС ПЕРЕПРОВЕРЯЕТСЯ
 *   Пока джоба ждала в очереди, розыгрыш могли отменить (`cancelled`) или
 *   разыграть (`drawn`). Добор участников в отменённый розыгрыш бессмыслен,
 *   а в разыгранный — недопустим: состав участников на момент тиража должен
 *   быть зафиксирован. Поэтому `backfill()` работает только со статусом
 *   `active`.
 */
class BackfillRaffleEntriesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Сбой записи повторяем: вставка идемпотентна. */
    public int $tries = 3;

    /** Минуты на восстановление БД, после — ошибка видна в очереди. */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $raffleId,
    ) {}

    /** Поставить добор в очередь (см. `EnterRafflesJob::forOrder()`). */
    public static function forRaffle(Raffle $raffle): PendingDispatch
    {
        return self::dispatch(raffleId: (int) $raffle->getKey());
    }

    public function handle(RaffleService $raffles): void
    {
        $raffle = Raffle::query()->find($this->raffleId);

        // Розыгрыш удалён — каскад унёс призы, билеты и победителей.
        if ($raffle === null) {
            return;
        }

        $raffles->backfill($raffle);
    }
}
