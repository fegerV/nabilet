<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Domain;

use Nabilet\Modules\Loyalty\StateMachines\RaffleStateMachine;

/**
 * Окно розыгрыша: заказы, оплаченные в этот период, становятся билетами.
 *
 * ПОЧЕМУ ЧИСТОЕ РЕШЕНИЕ, А НЕ МЕТОД МОДЕЛИ
 *   Правило «входит ли оплата в розыгрыш» исполняется в двух местах — на
 *   КАЖДОЙ оплате заказа (обсервер `OrderRaffleObserver`) и на backfill при
 *   активации розыгрыша. Разойтись они не должны: backfill, набравший других
 *   участников, чем обсервер, — это розыгрыш, в котором состав зависел бы от
 *   того, когда организатор нажал кнопку. Поэтому правило живёт фреймворк-фри
 *   и проверяется без базы (`tests/run.php` не грузит Eloquent), а модель
 *   (`Raffle`) только передаёт сюда свои атрибуты — тот же приём, что у
 *   `Sessions\Domain\SalesWindow`.
 *
 * ПОЧЕМУ ГРАНИЦЫ NULLABLE И ЧТО ОНИ ЗНАЧАТ
 *   `starts_at = NULL` — окно открыто с начала времён: заказ, оплаченный до
 *   создания розыгрыша, легально участвует в розыгрыше «за всё время».
 *   `ends_at = NULL` — окно закрывается только тиражем. Магическая дата
 *   (1970-01-01) вместо NULL сделала бы «с начала времён» невыразимым: у
 *   заказов даты раньше неё не бывает, но и проверить это нельзя.
 *
 * ПОЧЕМУ СТАТУС ВНУТРИ ПРЕДИКАТА
 *   Отбор розыгрышей для входа идёт по статусу (`WHERE status = 'active'`),
 *   и предикат дублирует эту проверку на случай, если окно оценивают уже
 *   загруженный розыгрыш: черновик, разыгранный и отменённый розыгрыш не
 *   набирает участников ни при каком совпадении дат. */
final class RaffleWindow
{
    public function __construct(
        public readonly string $status,
        public readonly ?\DateTimeInterface $startsAt,
        public readonly ?\DateTimeInterface $endsAt,
    ) {
    }

    /**
     * Входит ли оплата в момент `$moment` в окно розыгрыша.
     *
     * Границы включительные (`>=` / `<=`): оплата ровно в `starts_at` или
     * ровно в `ends_at` — внутри окна, а не на его краю. Иначе заказ,
     * оплаченный в ту же микросекунду, что открытие окна, зависел бы от
     * порядка сравнения, а не от правила.
     */
    public function covers(?\DateTimeInterface $moment = null): bool
    {
        if (!in_array($this->status, RaffleStateMachine::acceptingEntries(), true)) {
            return false;
        }

        $moment ??= new \DateTimeImmutable('now');

        if ($this->startsAt !== null && $moment < $this->startsAt) {
            return false;
        }

        if ($this->endsAt !== null && $moment > $this->endsAt) {
            return false;
        }

        return true;
    }
}
