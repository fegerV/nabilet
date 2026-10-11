<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Users\Domain;

/**
 * Словарь `users.status` — в одном месте.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ КЛАСС. До него словарь существовал ровно в одном виде —
 * строкой `'sometimes|in:active,inactive,banned'` в `UserController::update()`.
 * Из этого следовало две вещи, обе плохие:
 *
 *  1. Правило валидации — единственное место, где словарь вообще был записан.
 *     Любая запись мимо HTTP (сидер, скрипт, будущий эндпоинт) кладёт в колонку
 *     что угодно: тогда CHECK-ограничения на `users.status` в схеме не было.
 *     Теперь есть — `ck_users_status` (сплит `013_users_status_check.sql`).
 *  2. `UserService::authenticate()` не смотрел на статус вообще, поэтому
 *     `status = 'banned'` не мешал войти заново. Бан отзывал сессии
 *     (`UserSessionRevocationTest`), и человек получал новые тем же паролем —
 *     то есть «лишение доступа» было отключением до следующего логина.
 *
 * Значения НЕ выдуманы: `active` — дефолт колонки в схеме, `inactive` и `banned`
 * уже принимались API и уже используются тестами отзыва сессий. Здесь они лишь
 * сведены в одну точку, чтобы правило валидации и проверка при входе не могли
 * разойтись, как это случилось с тремя копиями политики паролей.
 *
 * @see docs/REVIEW-spec-bundle.md §3.25.6 — «словарь `users.status` не определён»
 *      (закрыто 2026-10-12: словарь существовал в стеке, но не был назван)
 */
final class UserStatus
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    public const BANNED = 'banned';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ACTIVE, self::INACTIVE, self::BANNED];
    }

    /** Готовое правило Laravel для поля `status`: `in:active,inactive,banned`. */
    public static function validationRule(): string
    {
        return 'in:' . implode(',', self::all());
    }

    /**
     * Разрешён ли вход в этом состоянии.
     *
     * Fail-closed: вход разрешён ТОЛЬКО при `active`. Неизвестное значение —
     * тоже отказ, а не «пропустим, раз непонятно». Теперь такое значение
     * отвергает и база (`ck_users_status`), но проверка здесь остаётся: она
     * закрывает строки, записанные до появления ограничения, и базы, где
     * ограничение не было применено (не-MySQL драйверы, старый MySQL).
     *
     * `inactive` — не «мягкий» флаг, а лишение доступа: так это уже понимает
     * отзыв сессий (`UserSessionRevocationTest`). Отказ во входе и отзыв
     * существующих сессий — две половины одного правила, и до этой правки
     * работала только вторая.
     */
    public static function mayAuthenticate(?string $status): bool
    {
        return $status === self::ACTIVE;
    }
}
