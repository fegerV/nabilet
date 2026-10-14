<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Domain;

/**
 * Правила уровней «Клуба зрителей»: какой уровень соответствует числу оплаченных заказов.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ ОБЪЕКТ, А НЕ ЗАПРОС В СЕРВИСЕ
 *   Само правило выбора — продуктовое решение («самый высокий уровень, чей порог
 *   не превышен числом заказов»), и оно проверяемо без базы: класс не знает ни
 *   Laravel, ни I/O (см. `tools/verify-purity.php`). Сами пороги живут в таблице
 *   `loyalty_levels` (их правит сид, а не код), а здесь — только функция выбора
 *   по готовому списку уровней.
 *
 * ЧТО ИМЕННО ИСПОЛЬЗУЕТСЯ
 *   `resolve()` — единственный метод. Принимает число оплаченных заказов и
 *   упорядоченный список уровней, возвращает выбранный уровень. Используется
 *   `LoyaltyLevelService` при пересчёте и при сборке ответа API.
 */
final class LoyaltyTierPolicy
{
    /** Стабильные slug-и уровней (зеркало сид-строк из миграции `020_loyalty_levels`). */
    public const START = 'start';
    public const FRIEND = 'friend';
    public const VIP = 'vip';

    /**
     * Выбрать самый высокий уровень, порог которого не превышен числом заказов.
     *
     * Уровни считаются упорядоченными по возрастанию `min_paid_orders` (как
     * отдаёт `LoyaltyLevel::ordered()`). Итерация по возрастанию и перезапись
     * выбранного при каждом прохождении порога даёт ровно «самый верхний
     * достигнутый» уровень — без сортировок и без привязки к конкретным slug.
     *
     * @param  array<int, array{slug: string, name: string, min_paid_orders: int}>  $levels
     * @return array{slug: string, name: string, min_paid_orders: int}|null  null, если список пуст
     */
    public static function resolve(int $paidOrders, array $levels): ?array
    {
        $chosen = null;

        foreach ($levels as $level) {
            if ($paidOrders >= (int) $level['min_paid_orders']) {
                $chosen = $level;
            }
        }

        return $chosen;
    }
}
