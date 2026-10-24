<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * Отпечаток токена для хранения и поиска: один алгоритм, одна длина.
 *
 * ЗАЧЕМ. `hash('sha256', $value)` стоял в десяти местах, и семь из них
 * заполняют колонки `CHAR(64)`:
 *
 * | колонка                        | кто пишет / ищет |
 * |--------------------------------|------------------|
 * | `session_tokens.session_token_hash` | `Auth\Domain\SessionToken::hashOf()` |
 * | `qr_token_hash`                | `Tickets\Services\TicketService` |
 * | `device_token_hash`            | `Checkin\DeviceTokenIssuer::hash()` (запись) + `Checkin\Middleware\AuthenticateChecker::hash()` и `OfflineBundleController` (два чтения) |
 * | `api_keys.key_hash` ×2         | `Embed\Services\EmbedAccessService`, `Core\Idempotency\IdempotencyPolicy::keyHash()` |
 * | `idempotency_records.request_hash` | `IdempotencyPolicy::requestHash()` |
 * | `offline_bundles.bundle_hash`  | `Checkin\Domain\OfflineBundleBuilder` |
 *
 * `SessionToken` объявлял это правило правильно — `HASH_ALGORITHM` и
 * `HASH_LENGTH` с объяснением, что ширина задана колонкой, — но вызывать его
 * могли только свои: остальные девять писали тот же digest сами. Правило,
 * записанное верно в одном классе и скопированное в десять строк, — это не
 * одно правило, а одиннадцать.
 *
 * ПОЧЕМУ ИМЕННО SHA256 В HEX. Колонки `CHAR(64)`: hex от sha256 — ровно 64
 * символа. `hash('sha256', $v, true)` даёт 32 байта и не влезает; sha512 даёт
 * 128; md5 — 32. То есть алгоритм здесь не «сильный хеш вообще», а ровно тот,
 * чья hex-запись совпадает с шириной колонки. Менять алгоритм — менять схему.
 *
 * ПОЧЕМУ НЕ `password_hash()` И НЕ `Crypt::encrypt()`. Этот отпечаток нужен
 * ДЛЯ ПОИСКА: токен приходит в заголовке, и запрос идёт
 * `WHERE device_token_hash = :hash`. Обратимое шифрование или перцеведение
 * сделали бы сравнение в SQL невозможным — проверять пришлось бы выборкой.
 * Детерминированность здесь не слабость, а условие работы.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ КЛАСС, А НЕ В `SessionToken`. Потребители в пяти модулях,
 * и владелец в `Auth` заставил бы их зависеть от модуля аутентификации. В
 * `Core/Support` правило доступно всем и остаётся чистым.
 *
 * Усечение — НЕ здесь. `AccountTokenService` берёт первые 16 символов как
 * отпечаток пароля, а не как ключ поиска; правило «до скольки символов резать»
 * принадлежит ему. Здесь — только полный hex.
 *
 * @see SessionToken — схема `session_tokens`, ширина которой задаёт длину
 */
final class Sha256Hex
{
    /**
     * Алгоритм отпечатка.
     *
     * Единственное место, где он назван. Менять вместе с `LENGTH`, иначе
     * сменившийся алгоритм перестанет соответствовать колонкам `CHAR(64)`.
     */
    public const ALGORITHM = 'sha256';

    /**
     * Длина hex-записи алгоритма: 2 символа на байт × 32 байта.
     *
     * Ширина колонок `CHAR(64)` в `migrations.sql`. Объявлена константой,
     * чтобы смена алгоритма была видна здесь же, а не всплыла на `INSERT`.
     */
    public const LENGTH = 64;

    /**
     * Полный hex-отпечаток значения.
     *
     * Регистр нижний — как у `hash()` по умолчанию и как во всех колонках
     * проекта: поиск идёт по точному равенству, и принимать ещё и верхний
     * регистр значило бы расширять множество допустимых ключей.
     */
    public static function of(string $value): string
    {
        return hash(self::ALGORITHM, $value);
    }
}
