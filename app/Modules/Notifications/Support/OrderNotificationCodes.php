<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Support;

use Nabilet\Modules\Orders\Support\OrderEventName;

/**
 * Код шаблона письма по статусу заказа.
 *
 * Код совпадает с именем события заказа (`order.<status>`), которое определено
 * в OrderEventName и обслуживает также исходящие вебхуки. Держать два списка —
 * значит два места, где они расходятся, а расхождение видно только по
 * отсутствию писем на проде. Класс остаётся отдельным, потому что код шаблона
 * и имя события — разные вещи, которые СЕЙЧАС совпадают; если письма когда-то
 * разойдутся с вебхуками, меняется только этот файл.
 */
final class OrderNotificationCodes
{
    /**
     * Напоминание за сутки до мероприятия.
     *
     * ЕДИНСТВЕННЫЙ код, у которого нет парного статуса заказа. Напоминание — не
     * переход статуса, а наступление времени: заказ, купленный месяц назад, в
     * момент рассылки никто не трогает. Поэтому его не порождает OrderObserver
     * (он реагирует только на `wasChanged('status')`), а рассылает свип по
     * расписанию — см. Orders\Services\OrderReminderSweeper.
     *
     * Отсюда и отдельная константа, а не ветка в forStatus(): forStatus()
     * отображает СТАТУС на код, а у напоминания статуса нет. Смешивать их
     * значило бы завести фиктивный статус `reminder` в ck_orders_status.
     *
     * Письмо покупателю уходит только по оплаченному заказу: напоминание
     * бессмысленно без билета, который можно показать на входе.
     */
    public const REMINDER = 'order.reminder';

    public static function forStatus(string $status): ?string
    {
        return OrderEventName::forStatus($status);
    }

    /** @return list<string> */
    public static function all(): array
    {
        // Напоминание добавляется к событиям статусов, но в OrderEventName его
        // нет намеренно: там список = ck_orders_status, а вебхуков по
        // напоминанию партнёрам не обещали.
        return [...OrderEventName::all(), self::REMINDER];
    }
}
