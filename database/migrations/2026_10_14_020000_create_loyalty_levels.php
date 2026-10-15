<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 3 «Клуба зрителей»: уровни клуба (Start/Friend/VIP).
 *
 * ЗАЧЕМ ТАБЛИЦА, А НЕ КОНСТАНТА
 *   Пороги уровней — продуктовое решение, а не деталь реализации. Держать их в
 *   таблице значит менять «VIP за 10 заказов» на «за 8» правкой сида, а не
 *   релизом бэкенда. Сервис читает пороги из `loyalty_levels` и выбирает уровень
 *   по `COUNT(orders WHERE status='paid')`, не зная про базу внутри `Domain/`
 *   (см. `tools/verify-purity.php`).
 *
 * ПОЧЕМУ КОЛОНКА НА `users`
 *   Текущий уровень нужен на каждом рендере кабинета; `users.loyalty_level_id`
 *   лежит в той же строке, что и профиль, и читается бесплатно вместо JOIN к
 *   отдельной таблице статусов. NULL — уровень не пересчитан, трактуется как
 *   Старт. FK SET NULL: удаление уровня из конфига не уносит покупателей.
 *
 * ПЕРЕСЧЁТ
 *   Обсервер `OrderLoyaltyObserver` (зарегистрирован в `LoyaltyServiceProvider`)
 *   пересчитывает уровень при каждой смене статуса заказа — оплата поднимает,
 *   возврат/отмена опускают. Пишет `loyalty_level_id` только при смене значения.
 *
 * ЗЕРКАЛО: `nabilet_core_spec/migrations/020_loyalty_levels.sql`, консолидированные
 * `migrations.sql` (корень и бандл).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loyalty_levels')) {
            Schema::create('loyalty_levels', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);

                // Стабильный идентификатор уровня: ключ выбора и поле ответа API.
                $table->string('slug', 32);
                $table->string('name', 64);
                // Порог в оплаченных заказах. 0 — для любого нового аккаунта.
                $table->integer('min_paid_orders')->default(0);
                $table->integer('sort_order')->default(0);

                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);

                $table->unique(['public_id'], 'uq_loyalty_levels_public_id');
                $table->unique(['slug'], 'uq_loyalty_levels_slug');
            });
        }

        if (! Schema::hasColumn('users', 'loyalty_level_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('loyalty_level_id')->nullable();

                $table->foreign('loyalty_level_id', 'fk_users_loyalty_level')
                    ->references('id')->on('loyalty_levels')->nullOnDelete();
            });
        }

        // Сид уровней фазы 3. `DB::statement` совместим и с verify-migrations
        // (стуб лишь записывает сырой SQL, не исполняя его), и с реальным
        // приложением. Идемпотентность — через `DB::select`: в стабе select всегда
        // пуст, поэтому сид записывается; в бою повторный прогон миграции пропускает
        // уже существующие строки (уникальный `slug` страхует от дубля).
        // `public_id` — те же фиксированные строки, что в зеркале-сплите, чтобы обе
        // копии схемы несли одинаковые данные (см. `020_loyalty_levels.sql`).
        if (DB::select('SELECT 1 FROM loyalty_levels LIMIT 1') === []) {
            $now = date('Y-m-d H:i:s.u');

            $rows = [
                ['01ARZ3NDEKTSV4RRFFQ69G5FAV', 'start', 'Старт', 0, 1],
                ['01ARZ3NDEKTSV4RRFFQ69G5FAW', 'friend', 'Друг', 3, 2],
                ['01ARZ3NDEKTSV4RRFFQ69G5FAX', 'vip', 'VIP', 10, 3],
            ];

            foreach ($rows as [$publicId, $slug, $name, $min, $sort]) {
                DB::statement(
                    'INSERT INTO loyalty_levels (public_id, slug, name, min_paid_orders, sort_order, created_at, updated_at) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$publicId, $slug, $name, $min, $sort, $now, $now]
                );
            }
        }
    }

    public function down(): void
    {
        // Необратимо по замыслу: снятие уровней лишает покупателей статуса клуба,
        // а снятие колонки на `users` разрывает связь со статусом. Сид не
        // откатывается намеренно — пустая таблица уровней сломала бы сервис.
    }
};
