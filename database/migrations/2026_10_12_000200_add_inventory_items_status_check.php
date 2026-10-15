<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ck_inventory_status — словарь `inventory_items.status` переезжает в базу.
 *
 * ЧТО БЫЛО НЕ ТАК
 *   У `inventory_items.status` не было CHECK, а `InventoryItemStateMachine` —
 *   класс, против которого схема обязана сверяться, — не перечислял все
 *   значения, которые пишет приложение. Словари разошлись, и заметить это было
 *   нечем.
 *
 *   `CartItemService` пишет `sold_out`, когда вместимость позиции исчерпана.
 *   Для МЕСТА это обычный случай, а не край: у места `capacity = 1`, поэтому
 *   первое добавление в корзину обнуляет `available_quantity` и статус
 *   становится `sold_out` (`held` выбирается только пока остались свободные —
 *   то есть для стоячих зон). `OrderService` говорит то же самое прямым текстом:
 *   «до оплаты status может быть held/sold_out».
 *
 *   Так как состояния `sold_out` в машине не было, `can('sold_out', 'sold')`
 *   возвращал `false`, и `PaymentService::markInventorySoldForOrder()` — который
 *   охраняет запись этим вызовом (аудит R-12) — писал warning и пропускал
 *   строку. ОПЛАЧЕННАЯ позиция навсегда оставалась `sold_out`:
 *
 *     * она не попадала ни в одну отчётность по `sold`;
 *     * `OrderService` сбрасывает `sold_out` в `available` при отмене заказа так
 *       же охотно, как `held`, — то есть метка «продано», которая и должна
 *       защищать оплаченную единицу, не ставилась вообще.
 *
 *   Воспроизведено до правки и зафиксировано после —
 *   `tests/Feature/Inventory/InventorySoldOnPaymentTest.php`, покупка идёт
 *   настоящим путём: корзина → checkout → оплата. Прежнее покрытие промахнулось,
 *   потому что `PaymentInitiationGuardsTest::pendingOrder()` создаёт заказ
 *   напрямую через `OrderService` в обход корзины, и позиция остаётся в
 *   `available`, а переход `available → sold` был разрешён и раньше.
 *
 * ПОЧЕМУ ЗНАЧЕНИЯ НЕ ВЫДУМАНЫ
 *   Шесть значений — это пересечение того, что уже пишут авторы записей
 *   (`CartItemService`, `OrderService`, `PaymentService`), и того, что уже ждёт
 *   витрина: `resources/js/lib/seatStatus.ts` документирует словарь бэкенда как
 *   `available | held | sold_out | sold | blocked | disabled`. Здесь база лишь
 *   начинает требовать то, о чём остальной стек уже договорился, — принцип AD-12.
 *
 *   `blocked` и `disabled` пока не пишет никто: они объявлены для админки.
 *   Включены потому, что выкинуть объявленные состояния — продуктовое решение,
 *   а не уборка.
 *
 * ПОЧЕМУ НЕ ПРАВКА 2026_09_20_001000
 *   Та миграция уже прошла на всех развёрнутых базах: ограничение, дописанное
 *   туда, существовало бы в бандле и больше нигде. Отдельный файл применяется и
 *   к существующим, и к свежим установкам — ровно поэтому существуют
 *   `2026_10_05_000200_constrain_carts_unique_to_active.php` и
 *   `2026_10_12_000100_add_users_status_check.php`.
 *
 * Зеркала: `nabilet_core_spec/migrations/014_inventory_status_check.sql`,
 * консолидированные `migrations.sql` (корень и бандл).
 *
 * MySQL/MariaDB ONLY — как и у остальных CHECK-ограничений: на движке, который
 * CHECK не исполняет, оператор молча получил бы необеспеченный инвариант, а
 * `migrate:status` показал бы «Ran».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->enforcesCheckConstraints()) {
            fwrite(STDERR, sprintf(
                "\n  ! SKIPPED add_inventory_items_status_check: driver \"%s\" cannot add a CHECK\n"
                . "    to an existing table. `inventory_items.status` therefore accepts any string,\n"
                . "    and ck_inventory_status is NOT enforced. migrate:status will still show\n"
                . "    this migration as Ran.\n\n",
                DB::connection()->getDriverName()
            ));

            return;
        }

        DB::statement(
            "ALTER TABLE inventory_items ADD CONSTRAINT ck_inventory_status "
            . "CHECK (status IN ('available','held','sold_out','sold','blocked','disabled'))"
        );
    }

    /**
     * Драйверы, которые действительно исполняют CHECK на существующей таблице.
     *
     * MySQL 8.0.16+ и MariaDB 10.2.1+. Более старый MySQL разбирает и молча
     * игнорирует CHECK — именно этот случай гард и делает слышимым.
     */
    private function enforcesCheckConstraints(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function down(): void
    {
        // Необратимо по замыслу: снятие инварианта ослабляет базу.
    }
};
