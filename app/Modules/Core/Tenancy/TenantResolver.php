<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Определение организации для строки класса A по её родителю.
 *
 * ЗАЧЕМ. `TenantScoped` заполняет `organization_id` из контекста запроса, но
 * контекста нет в законных системных путях:
 *
 *   - вебхук платёжного провайдера (`PaymentService::processWebhook` работает
 *     внутри `withoutScope()`) выпускает билеты;
 *   - гостевой checkout: покупатель не аутентифицирован, членства нет, а заказ
 *     всё равно принадлежит организатору сеанса;
 *   - планировщик и консольные команды.
 *
 * Во всех этих случаях организация НЕ неизвестна: она выводится из родителя —
 * билет из заказа, заказ из сеанса, сеанс из события. Этот класс хранит такую
 * карту `(таблица → внешний ключ → родитель)` и поднимается по цепочке, пока не
 * встретит строку с заполненным `organization_id`.
 *
 * ПОЧЕМУ НЕ «взять первую организацию». Угадывание означало бы запись чужому
 * тенанту. Если цепочка обрывается (нет родителя, у родителя NULL), метод
 * возвращает `null`: решение остаётся за вызывающим, а обязательность колонки
 * проверяет БД.
 *
 * Карта покрывает ровно таблицы класса A из
 * `2026_10_29_000100_add_organization_id_to_tenant_tables.php`. Таблицы, у
 * которых `organization_id` уже был (events, venues, orders, promo_codes, pages,
 * webhooks, ab_experiments), — конец цепочки.
 */
final class TenantResolver
{
    /**
     * `таблица => [внешний ключ, родительская таблица]`.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PARENTS = [
        // Продажи
        'sessions' => ['event_id', 'events'],
        'inventory_items' => ['session_id', 'sessions'],
        'carts' => ['session_id', 'sessions'],
        'cart_items' => ['cart_id', 'carts'],
        'seat_holds' => ['cart_id', 'carts'],
        'order_items' => ['order_id', 'orders'],
        'promo_code_redemptions' => ['promo_code_id', 'promo_codes'],

        // Платежи и билеты
        'payments' => ['order_id', 'orders'],
        'payment_transactions' => ['payment_id', 'payments'],
        'refunds' => ['order_id', 'orders'],
        'tickets' => ['order_id', 'orders'],
        'ticket_scans' => ['ticket_id', 'tickets'],
        'offline_bundles' => ['checkin_device_id', 'checkin_devices'],

        // Залы и схемы
        'halls' => ['venue_id', 'venues'],
        'hall_schema_versions' => ['hall_id', 'halls'],
        'sectors' => ['schema_version_id', 'hall_schema_versions'],
        'hall_rows' => ['sector_id', 'sectors'],
        'seats' => ['row_id', 'hall_rows'],
        'hall_tables' => ['sector_id', 'sectors'],
        'standing_zones' => ['sector_id', 'sectors'],

        // Переводы и контент события
        'event_translations' => ['event_id', 'events'],
        'venue_translations' => ['venue_id', 'venues'],
        'page_translations' => ['page_id', 'pages'],
        'event_dates' => ['event_id', 'events'],
        'event_speakers' => ['event_id', 'events'],
        'event_sponsors' => ['event_id', 'events'],
        'event_faqs' => ['event_id', 'events'],
        'event_artists' => ['event_id', 'events'],
        'event_schedule_items' => ['event_id', 'events'],
        'artist_event' => ['event_id', 'events'],

        // A/B-эксперименты
        'ab_variants' => ['experiment_id', 'ab_experiments'],
        'ab_assignments' => ['experiment_id', 'ab_experiments'],
        'ab_metrics' => ['experiment_id', 'ab_experiments'],

        // Вебхуки
        'webhook_deliveries' => ['webhook_id', 'webhooks'],

        // Лояльность
        'event_favorites' => ['event_id', 'events'],
        'event_follows' => ['event_id', 'events'],
        'follow_notifications' => ['event_id', 'events'],
        'raffle_prizes' => ['raffle_id', 'raffles'],
        'raffle_entries' => ['order_id', 'orders'],
        'raffle_winners' => ['raffle_id', 'raffles'],
    ];

    /**
     * Организация по атрибутам создаваемой строки.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromAttributes(string $table, array $attributes): ?int
    {
        $currentTable = $table;
        $current = $attributes;
        $visited = [];

        while (isset(self::PARENTS[$currentTable])) {
            // Карта статична и не содержит циклов, но защита от них дешевле
            // зависшего запроса, если карту однажды отредактируют с ошибкой.
            if (isset($visited[$currentTable])) {
                return null;
            }

            $visited[$currentTable] = true;

            [$foreignKey, $parentTable] = self::PARENTS[$currentTable];
            $parentId = $current[$foreignKey] ?? null;

            if ($parentId === null || $parentId === '') {
                return null;
            }

            $parent = DB::table($parentTable)->where('id', $parentId)->first();

            if ($parent === null) {
                return null;
            }

            $parent = (array) $parent;

            if (($parent['organization_id'] ?? null) !== null) {
                return (int) $parent['organization_id'];
            }

            $currentTable = $parentTable;
            $current = $parent;
        }

        return null;
    }

    /**
     * Таблица вообще участвует в цепочке (для диагностики и тестов).
     */
    public static function knows(string $table): bool
    {
        return isset(self::PARENTS[$table]);
    }
}
