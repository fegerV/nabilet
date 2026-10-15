<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Support;

/**
 * Коды писем подписчикам — ПОВОДЫ, а не статусы.
 *
 * Отдельный класс, а не ветка в `OrderNotificationCodes`: у заказа код письма
 * выводится из статуса (`order.<status>`), у подписки статуса нет вообще —
 * повод («появился сеанс», «открылись продажи») не хранится ни в одной строке
 * и потому не может быть «с текущим значением» сверен. Сводить их в один
 * список значило бы завести фиктивный статус подписки.
 *
 * Значение — одновременно код шаблона в `notification_templates` и `type` в
 * `notifications`, как и у заказов: одна строка списка вместо таблицы
 * соответствий. Плюс он же стоит третьим компонентом ключа дедупликации
 * `uq_follow_notifications_dedupe` — именно поэтому по одному сеансу может
 * уйти два разных письма (и только по одному каждого вида).
 */
final class FollowNotificationCodes
{
    /** У отслеживаемого мероприятия появился новый сеанс. */
    public const NEW_SESSION = 'follow.new_session';

    /** На сеанс открылись продажи — можно покупать. */
    public const SALES_OPEN = 'follow.sales_open';

    /**
     * Переменные, которые подставляет `FollowNotificationData`.
     *
     * Отдельный список, как у `AccountNotificationCodes::variables()`: в
     * редакторе шаблонов админке не должно предлагаться `order_number` в
     * письме о сеансе. Названия намеренно совпадают с заказными там, где
     * смысл тот же (`event_name`, `event_date`, `venue_name`) — один язык
     * шаблонов вместо двух похожих.
     *
     * `sales_note` — готовая фраза о состоянии продаж («Билеты уже в
     * продаже», «Продажи откроются 20.10.2026 19:00», «Продажи скоро
     * начнутся»). Она, а не сырая дата, потому что в двух шаблонах эта фраза
     * встречается в разном окружении, а собирать её условиями в тексте
     * шаблона невозможно.
     *
     * @return list<string>
     */
    public static function variables(): array
    {
        return [
            'customer_name',
            'event_name',
            'event_date',
            'venue_name',
            'action_url',
            'sales_note',
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::NEW_SESSION, self::SALES_OPEN];
    }
}
