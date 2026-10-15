<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\StateMachines;

use Nabilet\Core\StateMachine\StateMachine;

/**
 * Жизненный цикл розыгрыша (фаза 5, «счастливый билет»).
 *
 * `draft` — розыгрыш готовится: призы правятся, участники не набираются.
 * `active` — оплаченные заказы из окна становятся билетами (обсервер заказа
 * зовёт `RaffleService::enterForOrder()` только в этом статусе).
 * `drawn` — тираж проведён организатором кнопкой; терминален: результаты
 * не переписываются, а победители уже получили письма.
 * `cancelled` — розыгрыш закрыт без тиража; терминален по той же причине.
 *
 * ПОЧЕМУ `active → draft` РАЗРЕШЁН, А `drawn → *` НЕТ
 *   Пауза в наборе участников — рабочая ситуация: организатор открыл окно,
 *   увидел не тот период и хочет его поправить. Уже созданные билеты при
 *   этом остаются: они созданы из оплаченных заказов, и отзыв участия
 *   за оплаченную покупку — не решение схемы, а решение продукта, которого
 *   нет (см. `022_raffles.sql`: возврат билет не отзывает). Повторная
 *   активация запускает backfill заново — он идемпотентен
 *   (`UNIQUE (raffle_id, order_id)`), поэтому paused-период ничего не
 *   дублирует. Тираж же необратим: `raffle_winners` ссылаются на билеты и
 *   призы, письма победителям ушли, и «переиграть» значило бы изменить
 *   выплаченные призы.
 *
 * Словарь статусов закрыт CHECK `ck_raffles_status` (сплит 022), а
 * `tools/verify-state-machines.php` сверяет объявленные состояния с базой —
 * так же, как у остальных семи машин.
 */
final class RaffleStateMachine
{
    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const DRAWN = 'drawn';

    public const CANCELLED = 'cancelled';

    public static function make(): StateMachine
    {
        return StateMachine::define(
            name: 'Raffle',
            initial: self::DRAFT,
            states: [self::DRAFT, self::ACTIVE, self::DRAWN, self::CANCELLED],
            transitions: [
                self::DRAFT => [self::ACTIVE, self::CANCELLED],
                // Пауза в наборе участников: собранные билеты остаются.
                self::ACTIVE => [self::DRAFT, self::DRAWN, self::CANCELLED],
                self::DRAWN => [],
                self::CANCELLED => [],
            ],
            terminal: [self::DRAWN, self::CANCELLED],
        );
    }

    /** Статусы, в которых розыгрыш виден на витрине (кабинет и результаты). */
    public static function visible(): array
    {
        return [self::ACTIVE, self::DRAWN];
    }

    /** Статусы, в которых розыгрыш принимает новых участников. */
    public static function acceptingEntries(): array
    {
        return [self::ACTIVE];
    }
}
