<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Информационные киоски продажи билетов.
 *
 * Киоск — это УСТРОЙСТВО, а не пользователь: у него нет почты, пароля и
 * браузерской сессии. Как и чекер, он идентифицируется bearer-токеном,
 * которого таблица хранит только sha256-дайджест. Организация выводится
 * из токена middleware'ом, поэтому подделка org_id в запросе невозможна.
 *
 * `sales_enabled` — отдельный от `status` флаг: администратор может оставить
 * киоск на связи (heartbeat/конфиг работают), но ЗАПРЕТИТЬ продажу, не отзывая
 * токен. Это «выключить продажи» из админки без потери устройства.
 *
 * Блок `update_*` — серверная директива удалённого обновления. Сервер НЕ
 * хранит бинарник и не выполняет код на киоске: он только публикует версию,
 * URL и контрольную сумму, а киоск сам решает, когда и как обновиться, и
 * сообщает результат через `/api/v1/kiosk/update-result`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kiosk_devices')) {
            return;
        }

        Schema::create('kiosk_devices', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->string('name', 255);
            $table->string('location_address', 500)->nullable();
            $table->string('location_note', 500)->nullable();

            // active | disabled | revoked — CHECK ставится отдельной миграцией.
            $table->string('status', 32)->default('active');
            $table->boolean('sales_enabled')->default(false);

            $table->char('kiosk_token_hash', 64);
            // Стабильный токен корзины: один киоск = одна корзина на сеанс.
            // Это тот же capability-механизм, что у гостя, но владелец — устройство.
            $table->char('cart_token', 36);

            $table->string('platform', 32)->default('web_kiosk');
            $table->string('app_version', 50)->nullable();
            $table->string('config_version', 50)->nullable();

            $table->string('target_version', 50)->nullable();
            $table->string('update_url', 512)->nullable();
            $table->char('update_checksum', 64)->nullable();
            // none | pending | applied | failed
            $table->string('update_status', 32)->default('none');
            $table->string('update_applied_version', 50)->nullable();
            $table->text('update_error')->nullable();
            $table->dateTime('update_issued_at', 6)->nullable();
            $table->dateTime('update_finished_at', 6)->nullable();

            $table->dateTime('last_seen_at', 6)->nullable();
            $table->dateTime('last_used_at', 6)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['public_id'], 'uq_kiosk_devices_public_id');
            $table->unique(['kiosk_token_hash'], 'uq_kiosk_devices_token');
            $table->unique(['cart_token'], 'uq_kiosk_devices_cart_token');
            $table->index(['organization_id'], 'idx_kiosk_devices_org');
            $table->index(['status'], 'idx_kiosk_devices_status');
        });
    }

    public function down(): void
    {
        // Необратимо по дизайну: удаление строк оторвет kiosk_sales от устройств
        // и уничтожит историю продаж. Откат — только новой миграцией.
    }
};
