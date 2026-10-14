<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Support;

/**
 * Код письма победителю розыгрыша (фаза 5 `docs/LOYALTY-PLAN.md`).
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС, А НЕ ВЕТКА В `OrderNotificationCodes`
 *   У заказа код письма выводится из статуса (`order.<status>`), у розыгрыша
 *   статуса заказа нет вовсе — повод один («вы выиграли приз») и не хранится
 *   ни в одной строке. Это тот же случай, что у `FollowNotificationCodes`:
 *   повод, а не статус, поэтому свой список.
 *
 * Значение — одновременно код шаблона в `notification_templates` и `type` в
 * `notifications`, как и у остальных писем: одна строка списка вместо таблицы
 * соответствий. Сидер (`NotificationTemplateSeeder`) сверяет свой набор с
 *   `all()` и падает при расхождении — шаблон без рассылки и рассылка без
 *   шаблона должны быть видны сразу.
 */
final class RaffleNotificationCodes
{
    /** Тираж проведён, этот покупатель занял призовое место. */
    public const WINNER = 'raffle.winner';

    /**
     * Переменные, которые подставляет `NotifyRaffleWinnersJob`.
     *
     * Отдельный список, как у `FollowNotificationCodes::variables()`: в
     * редакторе шаблонов админке не должно предлагаться `order_number` в
     * письме о розыгрыше. `action_url` ведёт на страницу результатов
     * (`#/raffles/{public_id}` — хэш-путь, тот же инвариант, что у ссылок
     * сброса пароля и реферальной ссылки: SPA отдаётся только на `/`).
     *
     * @return list<string>
     */
    public static function variables(): array
    {
        return [
            'customer_name',
            'raffle_title',
            'prize_place',
            'prize_title',
            'action_url',
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::WINNER];
    }
}
