<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Nabilet\Modules\Loyalty\Models\Raffle;
use Nabilet\Modules\Loyalty\Models\RaffleWinner;
use Nabilet\Modules\Loyalty\Services\RaffleService;
use Nabilet\Modules\Loyalty\Support\RaffleNotificationCodes;
use Nabilet\Modules\Notifications\Services\TransactionalMailService;

/**
 * Письма победителям розыгрыша (фаза 5).
 *
 * ПОЧЕМУ ОДНА ДЖОБА НА РОЗЫГРЫШ, А НЕ НА ПОБЕДИТЕЛЯ
 *   Призовых мест единицы, и fan-out на тысячи писем здесь не нужен: одна
 *   джоба обходит победителей и шлёт каждому письмо. Джоба на победителя
 *   означала бы N задач в очереди от одного нажатия кнопки при том же
 *   результате — ровно та же арифметика, что у `NotifyFollowersOfNewSessionJob`.
 *
 * ИДЕМПОТЕНТНОСТЬ — ПО `notified_at`
 *   Джоба повторяется (SMTP падает, воркер убит по `--max-time`), и повтор
 *   не должен слать второе письмо. Отметка `raffle_winners.notified_at`
 *   проставляется после успешной отправки (и после «шаблон выключен» — это
 *   решение администратора, а не сбой транспорта), поэтому победители
 *   отбираются условием `notified_at IS NULL`.
 *
 * ПОЧЕМУ ТРАНСПОРТНЫЙ СБОЙ ПРОБРАСЫВАЕТСЯ
 *   `TransactionalMailService` уже записала ошибку в `notifications`; если
 *   джоба её проглотит, победитель останется без письма, а в очереди не
 *   будет следа. Исключение уходит наверх — Laravel повторит джобу по
 *   `tries`/`backoff`, и следующий проход заберёт тех же ненадённых
 *   победителей.
 */
class NotifyRaffleWinnersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Ошибка SMTP повторяется: письмо победителю обязано дойти. */
    public int $tries = 3;

    /** Несколько минут на восстановление SMTP, после — ошибка видна в очереди. */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $raffleId,
    ) {}

    /** Поставить рассылку в очередь (см. `EnterRafflesJob::forOrder()`). */
    public static function forRaffle(Raffle $raffle): PendingDispatch
    {
        return self::dispatch(raffleId: (int) $raffle->getKey());
    }

    public function handle(RaffleService $raffles, TransactionalMailService $mail): void
    {
        $raffle = Raffle::query()->find($this->raffleId);

        // Розыгрыш удалён — каскад унёс победителей, слать некому и нечего.
        if ($raffle === null) {
            return;
        }

        // Уведомлять есть смысл только после тиража: победители
        // появляются в нём, и до него список пуст.
        if ($raffle->status !== Raffle::STATUS_DRAWN) {
            return;
        }

        foreach ($raffles->winnersPendingNotification($raffle) as $winner) {
            $this->notify($mail, $winner);
        }
    }

    private function notify(TransactionalMailService $mail, RaffleWinner $winner): void
    {
        $recipient = $this->recipientFor($winner);

        if ($recipient === null) {
            // Адресата нет: аккаунт удален, а заказ победителя не хранит
            // адрес (теоретически). Отметку «уведомлён» не ставим — при
            // появлении адреса письмо уйдёт на следующем проходе.
            return;
        }

        // Исключение транспорта намеренно НЕ ловится: `notified_at`
        // остаётся NULL, Laravel повторяет джобу по `tries`/`backoff`, и
        // следующий проход заберёт этого победителя снова.
        $mail->send(
            code: RaffleNotificationCodes::WINNER,
            recipient: $recipient,
            variables: $this->variablesFor($winner),
            userId: $winner->user_id !== null ? (int) $winner->user_id : null,
        );

        // Сюда доходят и «письмо ушло», и «шаблон выключен/не найден»
        // (`send()` вернул null): второе — решение администратора, а не
        // сбой, и повторять такое письмо по backoff незачем.
        $winner->markNotified();
    }

    /**
     * Адрес победителя: аккаунт, иначе адрес заказа-билета.
     *
     * Победитель может быть гостем без аккаунта — в этом случае адрес живёт
     * только в заказе (`customer_email`, NOT NULL). Снимок `user_id` на
     * победителе — уточнение «кто это», а не источник адреса.
     */
    private function recipientFor(RaffleWinner $winner): ?string
    {
        $fromUser = $winner->user?->email;

        if (is_string($fromUser) && trim($fromUser) !== '') {
            return $fromUser;
        }

        $fromOrder = $winner->entry?->order?->customer_email;

        if (is_string($fromOrder) && trim($fromOrder) !== '') {
            return $fromOrder;
        }

        return null;
    }

    /**
     * Переменные шаблона `raffle.winner` — ровно список
     * `RaffleNotificationCodes::variables()`, не больше.
     *
     * @return array<string, scalar|null>
     */
    private function variablesFor(RaffleWinner $winner): array
    {
        $raffle = $winner->raffle;
        $prize = $winner->prize;

        return [
            'customer_name' => $this->nameFor($winner),
            'raffle_title' => (string) ($raffle?->title ?? ''),
            'prize_place' => (int) ($prize?->place ?? 0),
            'prize_title' => (string) ($prize?->title ?? ''),
            // Хэш-путь: SPA отдаётся только на `/`, чистый путь ушёл бы в
            // `Route::fallback()` — тот же инвариант, что у сброса пароля и
            // реферальной ссылки.
            'action_url' => rtrim((string) config('app.url'), '/')
                . '/#/raffles/' . (string) ($raffle?->public_id ?? ''),
        ];
    }

    /**
     * Имя победителя для приветствия.
     *
     * Имя аккаунта, иначе «покупатель»: письмо о выигрыше не место разбирать,
     * почему у человека нет имени, а `customer_name` в шаблоне ждёт строку.
     */
    private function nameFor(RaffleWinner $winner): string
    {
        $name = $winner->user?->name;

        if (is_string($name) && trim($name) !== '') {
            return $name;
        }

        return 'покупатель';
    }
}
