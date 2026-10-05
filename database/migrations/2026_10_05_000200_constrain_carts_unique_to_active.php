<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Вторая покупка в одном сеансе падала с 500.
 *
 * ЧТО ЛОМАЛОСЬ
 *   Ключ `uq_carts_token_session_status (cart_token, session_id, status)` из
 *   `2026_09_29_000100_add_cart_token_and_order_cart_id` запрещает больше одной
 *   корзины на пару (токен, сеанс) В КАЖДОМ статусе. Первая покупка переводит
 *   корзину в `converted`, вторая создаёт новую активную и при оформлении тоже
 *   пытается стать `converted` — и получает
 *
 *     SQLSTATE[23000] 1062 Duplicate entry
 *     '2b5ab515-…-1-converted' for key 'carts.uq_carts_token_session_status'
 *
 *   то есть сырой 500 `INTERNAL_ERROR` на обычном действии «купить ещё раз».
 *   Воспроизведено на живом стенде: второй POST /api/v1/cart/checkout той же
 *   корзиной-покупателем по тому же сеансу.
 *
 * ЧТО НУЖНО БЫЛО
 *   Инвариант D5 — «два параллельных покупателя одного сеанса работают в РАЗНЫХ
 *   корзинах» — требует уникальности только среди АКТИВНЫХ корзин. Исторические
 *   `converted` и `abandoned` ограничивать нечем: их может быть сколько угодно.
 *
 * ПОЧЕМУ ГЕНЕРИРУЕМАЯ КОЛОНКА
 *   Частичных индексов MySQL не умеет, поэтому уникальность «только для active»
 *   выражается вычисляемой колонкой: у активной корзины она равна
 *   `<токен>-<сеанс>`, у всех прочих — NULL. Уникальный индекс допускает сколько
 *   угодно NULL, поэтому ограничение действует ровно на активные корзины.
 *   `COALESCE(cart_token, '')` — для корзин авторизованного покупателя, у которых
 *   токена нет: ключом становится сеанс, и одна активная корзина на сеанс
 *   сохраняется.
 *
 * ПОЧЕМУ `VIRTUAL`, А НЕ `STORED`
 *   `STORED` на этой таблице не проходит: MySQL пересобирает её
 *   (`ALGORITHM=COPY`) и заново создаёт внешние ключи, а это падает с
 *   `ERROR 1215 (HY000): Cannot add foreign key constraint`. Проверено обеими
 *   формами на живом стенде: `STORED` — ошибка, `VIRTUAL` — успех. Причина
 *   отказа в пересоздании ключей — отдельная несоответствие схемы, эта миграция
 *   её не трогает. `VIRTUAL` считается на чтении, места не занимает, и
 *   уникальный индекс по нему поддерживается.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasIndex('carts', 'uq_carts_token_session_status')) {
            Schema::table('carts', function (Blueprint $table): void {
                $table->dropUnique('uq_carts_token_session_status');
            });
        }

        if (! Schema::hasColumn('carts', 'active_cart_key')) {
            DB::statement(
                "ALTER TABLE carts ADD COLUMN active_cart_key VARCHAR(128) "
                . "GENERATED ALWAYS AS ("
                . "IF(status = 'active', CONCAT(COALESCE(cart_token, ''), '-', session_id), NULL)"
                . ") VIRTUAL"
            );
        }

        if (! $this->hasIndex('carts', 'uq_carts_active')) {
            DB::statement('CREATE UNIQUE INDEX uq_carts_active ON carts (active_cart_key)');
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('carts', 'uq_carts_active')) {
            DB::statement('DROP INDEX uq_carts_active ON carts');
        }

        if (Schema::hasColumn('carts', 'active_cart_key')) {
            Schema::table('carts', function (Blueprint $table): void {
                $table->dropColumn('active_cart_key');
            });
        }

        // ВНИМАНИЕ: возврат старого ключа возможен только если в таблице нет
        // нескольких `converted`/`abandoned` корзин на одну пару (токен, сеанс) —
        // то есть если после отката не осталось следов повторных покупок.
        // Иначе MySQL отвергнет создание индекса, и это ожидаемо: откат
        // восстанавливает дефект, а не данные.
        if (! $this->hasIndex('carts', 'uq_carts_token_session_status')) {
            Schema::table('carts', function (Blueprint $table): void {
                $table->unique(['cart_token', 'session_id', 'status'], 'uq_carts_token_session_status');
            });
        }
    }

    /**
     * Есть ли такой индекс.
     *
     * `DB::selectOne` с `DATABASE()`, а не `DB::table('information_schema.statistics')`:
     * это единственная форма, которую понимает dependency-free стенд
     * `tools/verify-migrations.php` (его стаб `DB` реализует
     * `statement`/`unprepared`/`selectOne`/`connection`, но не `table()` — на
     * `DB::table()` верификатор падал фаталом и не доходил до остальных проверок).
     * `DATABASE()` заодно избавляет от `DB::getDatabaseName()`. Ровно так же
     * проверяет индекс `2026_09_20_001200_fix_offline_bundles_unique_constraint.php`.
     */
    private function hasIndex(string $table, string $index): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index],
        );
    }
};
