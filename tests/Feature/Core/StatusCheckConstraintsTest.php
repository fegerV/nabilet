<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Словари статусов проверяет база, а не только код.
 *
 * Зачем отдельный файл. Правило проекта (AD-12): «если БД может это отклонить —
 * она должна». Пока словарь живёт одной строкой в правиле валидации или одним
 * списком в PHP-классе, любая запись мимо этого места (сидер, скрипт, правка
 * руками по SQL, будущий эндпоинт) кладёт в колонку произвольную строку, и
 * узнаётся об этом по расхождению в отчёте, а не по ошибке.
 *
 * Оба ограничения здесь появились именно поэтому:
 *   `ck_inventory_status` — `inventory_items.status` (сплит 014). Дефект был
 *     живым: машина не знала состояния `sold_out`, которым пишет корзина, и
 *     `PaymentService` не переводил оплаченную позицию в `sold`
 *     (`tests/Feature/Inventory/InventorySoldOnPaymentTest.php`).
 *   `ck_events_status` — `events.status` (сплит 015). Здесь код был
 *     дисциплинирован, а молчала только база; `verify-state-machines.php` писал
 *     «machine is unenforced» на каждом прогоне.
 *
 * Проверяется наблюдаемое поведение базы — отказ СУБД, а не наличие класса.
 */
class StatusCheckConstraintsTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    private function seedEvent(string $status): int
    {
        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Test Org',
            'slug' => 'org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        return (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => 'Событие',
            'slug' => 'event-' . Str::random(6),
            'status' => $status,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    public function test_the_database_accepts_every_inventory_status_the_code_writes(): void
    {
        [, , , $inventoryItemId] = $this->seedSellableSeat(available: 5);

        foreach (['available', 'held', 'sold_out', 'sold', 'blocked', 'disabled'] as $status) {
            DB::table('inventory_items')->where('id', $inventoryItemId)->update(['status' => $status]);

            $this->assertSame(
                $status,
                DB::table('inventory_items')->where('id', $inventoryItemId)->value('status')
            );
        }
    }

    public function test_the_database_rejects_an_inventory_status_outside_the_dictionary(): void
    {
        [, , , $inventoryItemId] = $this->seedSellableSeat(available: 5);

        $this->expectException(QueryException::class);

        DB::table('inventory_items')->where('id', $inventoryItemId)->update(['status' => 'reserved']);
    }

    /**
     * `finished` и `sold_out` — значения, которые фронтенд ошибочно считал
     * статусами мероприятия (`resources/js/lib/types.ts`). База их не принимает.
     */
    public function test_the_database_rejects_an_event_status_the_backend_never_had(): void
    {
        $this->expectException(QueryException::class);

        $this->seedEvent('finished');
    }

    public function test_the_database_accepts_every_event_status_the_machine_declares(): void
    {
        foreach (['draft', 'scheduled', 'published', 'completed', 'cancelled', 'archived'] as $status) {
            $this->assertGreaterThan(0, $this->seedEvent($status), "статус {$status} отвергнут базой");
        }
    }

    /**
     * `sold_out` мероприятия — то самое значение из ошибочного фронтенд-типа.
     * Отдельно от `finished`, потому что именно его мог бы прислать бейдж.
     */
    public function test_the_database_rejects_sold_out_as_an_event_status(): void
    {
        $this->expectException(QueryException::class);

        $this->seedEvent('sold_out');
    }
}
