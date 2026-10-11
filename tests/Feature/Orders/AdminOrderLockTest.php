<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Гонка данных в админском создании заказа (аудит архитектуры D1).
 *
 * `OrderService::createOrder` делает «check-then-decrement»: читает
 * `available_quantity`, сравнивает с запрошенным и лишь в конце транзакции
 * списывает. Без блокировки строки между чтением и списанием вторая
 * параллельная транзакция читает то же число и тоже проходит проверку —
 * то есть место продаётся дважды.
 *
 * Смоделировать настоящую конкуренцию внутри одного PHPUnit-процесса нельзя
 * (тест идёт в одной транзакции), поэтому проверяется механизм, который её
 * закрывает: чтение обязано идти с `FOR UPDATE`.
 */
class AdminOrderLockTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    public function test_reading_inventory_takes_a_row_lock(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 10);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(OrderService::class)->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $reads = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'inventory_items')
                && str_starts_with(strtolower(trim($sql)), 'select'),
        ));

        $this->assertNotEmpty($reads, 'Остаток позиции склада должен читаться отдельным SELECT-ом');
        $this->assertTrue(
            (bool) array_filter($reads, static fn (string $sql): bool => str_contains(strtolower($sql), 'for update')),
            'Чтение `inventory_items` обязано брать блокировку строки (lockForUpdate): иначе между '
            . 'проверкой остатка и `decrement` остаётся окно гонки, и место продаётся дважды (D1)',
        );
    }

    /**
     * Обратная сторона: блокировка не должна подменять доменную ошибку.
     * Нехватка мест — по-прежнему 422-совместимый `DomainRuleViolation`, а не
     * SQL CHECK violation (500).
     */
    public function test_not_enough_inventory_is_a_domain_error(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 1);

        $this->expectException(DomainRuleViolation::class);

        app(OrderService::class)->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 2]],
        ]);
    }
}
