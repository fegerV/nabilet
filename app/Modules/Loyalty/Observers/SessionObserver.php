<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Observers;

use Nabilet\Modules\Loyalty\Jobs\NotifyFollowersOfNewSessionJob;
use Nabilet\Modules\Loyalty\Support\FollowNotificationCodes;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Sessions\StateMachines\SessionStateMachine;

/**
 * Реакция на появление сеанса и открытие продаж: письмо подписчикам.
 *
 * ПОЧЕМУ ОБСЕРВЕР, А НЕ ВЫЗОВ ИЗ СЕРВИСА
 *   Сеансы создаются и правятся из нескольких мест (админка, сидеры, импорт),
 *   и рассылку из каждого — это гарантированно забытый путь. Здесь ловятся
 *   ВСЕ создания и ВСЕ правки, ровно как `OrderObserver` для статусов заказа.
 *
 * ПОЧЕМУ ДВА ПОВОДА, А НЕ ОДИН
 *   «Новый сеанс» и «открылись продажи» — разные новости. Сеанс, созданный
 *   заранее и пока без продаж, сообщается сразу («запомни дату»), а письмо
 *   «покупайте» уходит отдельно, когда организатор включит `on_sale`. Одно
 *   письмо на оба случая означало бы либо молчание о продажах, либо ложь
 *   «уже в продаже» про сеанс, который ещё не продаётся.
 *
 * ГРАНИЦА, КОТОРУЮ НАДО ЗНАТЬ
 *   Открытие продаж по ВРЕМЕНИ (`sales_start_at` наступил, статус уже
 *   `on_sale`, строку никто не менял) обсервер не видит: строка не меняется,
 *   события нет. Это дело свипа по расписанию — тот же приём, что
 *   `OrderReminderSweeper` для напоминаний. Наблюдатель покрывает создание
 *   сеанса и обе правки, которыми продажи открывают вручную.
 *
 * Джоба ставится `afterCommit()`: письмо не должно уйти от транзакции,
 *   которая откатилась, и не должно гонять сеть внутри неё.
 */
class SessionObserver
{
    public function created(Session $session): void
    {
        // Сеанс создан сразу в продаже — письмо о новом сеансе скажет и об этом
        // (`sales_note` во фразе «Билеты уже в продаже»). Создан без продаж —
        // только дата, а «покупайте» уйдёт из updated().
        $this->dispatch($session, FollowNotificationCodes::NEW_SESSION);
    }

    public function updated(Session $session): void
    {
        if ($session->wasChanged('status') && $session->status === SessionStateMachine::ON_SALE) {
            $this->dispatch($session, FollowNotificationCodes::SALES_OPEN);

            return;
        }

        // Продажи могли открыться правкой дат при уже включённом `on_sale`:
        // строку меняют, статус — нет, и без этой ветки такое открытие осталось
        // бы без письма. `isSellableAt()` проверяет и окно, и переключатель,
        // поэтому правка «открыть продажи завтрашней датой» условием не
        // проходит, а «открыть сейчас» — проходит.
        if ($session->wasChanged('sales_start_at') && $session->isSellableAt()) {
            $this->dispatch($session, FollowNotificationCodes::SALES_OPEN);
        }
    }

    private function dispatch(Session $session, string $templateCode): void
    {
        NotifyFollowersOfNewSessionJob::forSession($session, $templateCode)
            ->onConnection('database')
            ->afterCommit();
    }
}
