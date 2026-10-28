<?php

declare(strict_types=1);

namespace Nabilet\Modules\Users\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * How a contact value is compared — which is the database's decision, not PHP's.
 *
 * ОДНО ПРАВИЛО НА ВСЕ АДРЕСНЫЕ КОЛОНКИ
 *   `users.email`, `orders.customer_email`, `referrals.invited_email`,
 *   `newsletter_subscribers.email`, `social_accounts.email` — все
 *   `VARCHAR(255)` в `utf8mb4_unicode_ci`. Пространство имён `Users\Domain`
 *   историческое: класс завели ради регистрации, но правило принадлежит
 *   КОЛОНКЕ, а не модулю, поэтому им пользуются Auth, Orders, Loyalty и
 *   Notifications. Второй такой класс означал бы вторую копию правила.
 *
 * ЧТО РЕШАЕТ КОЛЛАЦИЯ
 *   MySQL считает `A@EXAMPLE.COM` и `a@example.com` одним значением, а
 *   (PAD SPACE) `a@example.com ` — тем же самым. Оба варианта отвергнуты
 *   `ERROR 1062` на MySQL 8.4 — см. `tools/repro-user-identity.sql`.
 *
 * ДВЕ АСИММЕТРИИ: ОДНА ВЫСТРЕЛИЛА, ВТОРАЯ ЗАМАСКИРОВАНА
 *   1. PHP СТРОЖЕ КОЛОНКИ — выстрелила. Форма, сравнивающая адреса через
 *      `===`/`!==`, отвечает «адрес свободен» и падает на дубликате ключа.
 *      Обратная сторона того же дефекта — объявить занятым СОБСТВЕННЫЙ адрес,
 *      набранный в другом регистре: `UserService::updateUser()` решал `!==`
 *      «изменился ли адрес», а `findByEmail()` в SQL решал «занят ли он», и два
 *      ответа противоречили друг другу — правка профиля отвечала 422 про чужой
 *      аккаунт (воспроизведено в `MeEndpointsTest`).
 *   2. PHP СЛАБЕЕ КОЛОНКИ — пока не выстрелила, и это важно не преувеличить.
 *      `strtolower()` и `strcasecmp()` складывают регистр только в ASCII:
 *      `strcasecmp('ИВАН@ПОЧТА.РФ', 'иван@почта.рф')` возвращает −32, тогда как
 *      колонка считает это одним значением. Сегодня до этого не доходит:
 *      правило `email:rfc` не пропускает не-ASCII, а `TrimStrings` срезает
 *      пробелы. Обе защиты — внешние по отношению к правилу, поэтому копии
 *      убраны не из-за инцидента, а потому что их совпадение с коллацией было
 *      случайным. `mb_strtolower()` ниже складывает ровно так же, как она.
 *
 * ПОЧЕМУ ТЕЛЕФОН ТОЛЬКО ОБРЕЗАЕТСЯ
 *   Stripping spaces and dashes from a phone number would make PHP STRICTER than
 *   the database: `+7 900 000 00 00` and `+79000000000` are two different values to
 *   a `utf8mb4_unicode_ci` column, because PAD SPACE ignores trailing whitespace
 *   only, not separators inside the string. A uniqueness pre-check that treated them
 *   as one person would refuse a registration the database is willing to store.
 *   Matching the collation exactly — trim, nothing more — is the honest answer, and
 *   the asymmetry between the two channels is recorded rather than smoothed over.
 */
final class ContactChannel
{
    public const EMAIL = 'email';
    public const PHONE = 'phone';

    public static function isValid(string $channel): bool
    {
        return $channel === self::EMAIL || $channel === self::PHONE;
    }

    /**
     * Reduce a contact value to the form the column compares — and STORES.
     *
     * Один и тот же вид нужен обеим сторонам. Если писать «как набрали», то
     * колонка будет считать два написания одним значением, а любое сравнение
     * в PHP — двумя, и вопрос «изменился ли адрес» получит два ответа. Поэтому
     * нормализуют не только перед сравнением, но и перед записью: у адреса
     * один канонический вид.
     */
    public static function normalise(string $channel, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return match ($channel) {
            self::EMAIL => $trimmed === '' ? null : mb_strtolower($trimmed, 'UTF-8'),
            self::PHONE => $trimmed === '' ? null : $trimmed,
            default => throw new DomainRuleViolation(
                sprintf('Unknown contact channel "%s".', $channel),
                'INVALID_CONTACT_CHANNEL'
            ),
        };
    }

    /** Would the column treat these two as the same row? */
    public static function sameIdentity(string $channel, ?string $left, ?string $right): bool
    {
        return self::normalise($channel, $left) === self::normalise($channel, $right);
    }
}
