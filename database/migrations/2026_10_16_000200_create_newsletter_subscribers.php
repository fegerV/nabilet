<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подписчики на рассылку афиши («Подписка» на витрине).
 *
 * ЗАЧЕМ ТАБЛИЦА, А НЕ «СОГЛАСИЕ В consents»
 *   consents хранит ФАКТ согласия (user xor anonymous) и не умеет хранить
 *   адрес получателя: письма без email не существует. Подписчик — отдельная
 *   сущность с уникальным email (без дублей), токеном отписки (неавторизован-
 *   ный владелец адреса имеет право отменить подписку без пароля — 152-ФЗ)
 *   и статусом словаря. Сам ФАКТ согласия дублируется в consents с
 *   anonymous_id = tokens uuid, чтобы субъект был различим для GDPR-запросов.
 *
 * БЕЗ ПИСЕМ В ЯДРЕ
 *   Таблица — хранилище согласий и адресов; сама рассылка (SyncArtistsCommand,
 *   SessionObserver уведомляет только following) остаётся вне этого файла.
 *   Статусы: subscribed | unsubscribed (словарь приложения, не CHECK — см.
 *   журнал свободных колонок в verify-state-machines).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique('uq_newsletter_email');
            // Токен отписки: даётся один раз в ответе подписки и используется
            // в ссылке отписки; хранится как есть (максимум — не секретнее email).
            $table->string('unsubscribe_token', 64)->unique('uq_newsletter_unsub_token');
            $table->string('status', 32)->default('subscribed');
            // Связка с `consents.anonymous_id`: субъект согласия (152-ФЗ §9).
            $table->char('anonymous_id', 36);
            $table->string('policy_version', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
