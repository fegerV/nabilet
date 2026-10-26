<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Domain;

use Nabilet\Core\Support\HmacSignature;

/**
 * Серверная проверка Telegram Mini App `initData` (ТЗ §17).
 *
 * ПОЧЕМУ ЭТО ОТДЕЛЬНЫЙ КЛАСС. Спека прямо требует: «Telegram `initData`
 * валидируется server-side до использования user data. `initDataUnsafe` не
 * является доверенным источником». Значит, подпись проверяется здесь, а не
 * «где-то в контроллере», и проверка не зависит от фреймворка — её можно
 * доказать без БД и без HTTP.
 *
 * АЛГОРИТМ (документация Telegram Web Apps):
 *   secret_key   = HMAC_SHA256(bot_token, "WebAppData")   // ключ — "WebAppData"
 *   hash         = HMAC_SHA256(data_check_string, secret_key)
 *   data_check_string = отсортированные по ключу пары `k=v`, склеенные "\n",
 *                       БЕЗ самой пары `hash`.
 *
 * ЧИСТЫЙ КЛАСС. Только `parse_str`, `json_decode` и владелец HMAC — ни
 * `vendor/`, ни `config()`, ни `app()`. Поэтому живёт в `Auth/Domain` и
 * проверяется dependency-free раннером.
 *
 * ПОЧЕМУ `HmacSignature`, А НЕ СВОЙ `hash_hmac`. Схема Telegram нестандартная
 * (ключ HMAC сам выводится из HMAC), и соблазн написать её «как в документации»
 * здесь — ровно тот дефект, который проект ловит кампанией: правило вычисления
 * подписи имеет одного владельца (`Core\Support\HmacSignature`), а не по копии
 * на каждый протокол. Сторож — `HmacSignatureCopyTest`; он и поймал первую
 * версию этого класса.
 */
final class TelegramInitData
{
    /** Срок жизни `init_data` по умолчанию — сутки (документация Telegram). */
    public const DEFAULT_MAX_AGE_SECONDS = 86400;

    /**
     * Ключ, из которого Telegram выводит секрет подписи. Не секрет: это
     * константа протокола, а не настройка.
     */
    private const SECRET_SEED = 'WebAppData';

    /**
     * Проверить подпись и свежесть. `null` — отказ (подпись не та, поля нет,
     * `auth_date` отсутствует или протух).
     *
     * @return array<string, mixed>|null проверенная нагрузка с раскодированным `user`
     */
    public static function verify(
        string $initData,
        string $botToken,
        int $now,
        int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS,
    ): ?array {
        if ($initData === '' || $botToken === '') {
            return null;
        }

        parse_str($initData, $fields);

        $hash = $fields['hash'] ?? null;
        if (! is_string($hash) || $hash === '') {
            return null;
        }

        unset($fields['hash']);

        ksort($fields);

        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key . '=' . (is_scalar($value) ? (string) $value : '');
        }

        $dataCheckString = implode("\n", $pairs);

        if (! HmacSignature::verify($dataCheckString, self::secretKey($botToken), $hash)) {
            return null;
        }

        $authDate = isset($fields['auth_date']) ? (int) $fields['auth_date'] : 0;
        if ($authDate <= 0 || $now - $authDate > $maxAgeSeconds) {
            return null;
        }

        if (isset($fields['user']) && is_string($fields['user'])) {
            $decoded = json_decode($fields['user'], true);
            if (is_array($decoded)) {
                $fields['user'] = $decoded;
            }
        }

        return $fields;
    }

    /**
     * Собрать `init_data` с валидной подписью — для тестов и локальной отладки.
     *
     * Существует ровно для того, чтобы тест не повторял алгоритм проверки
     * руками (иначе тест повторил бы ошибку реализации и ничего бы не поймал):
     * он строит вход по документации и подаёт его `verify()`.
     *
     * @param  array<string, mixed>  $fields  поля без `hash`
     */
    public static function sign(array $fields, string $botToken): string
    {
        // Строки, а не массивы: `user` у Telegram — JSON-строка, и если отдать
        // массив в `http_build_query`, он развернётся в `user[id]=…` — подпись
        // сойдётся, а полезная нагрузка развалится.
        $stringFields = [];
        foreach ($fields as $key => $value) {
            $stringFields[$key] = is_array($value) ? (string) json_encode($value) : (string) $value;
        }

        ksort($stringFields);

        $pairs = [];
        foreach ($stringFields as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        $secretKey = self::secretKey($botToken);
        $hash = HmacSignature::sign(implode("\n", $pairs), $secretKey);

        $stringFields['hash'] = $hash;

        return http_build_query($stringFields);
    }

    /**
     * Секрет подписи Telegram: HMAC от bot token с ключом `WebAppData`.
     *
     * Сырые байты, потому что это КЛЮЧ следующего HMAC, а не подпись на
     * проводе: hex-обёртка здесь дала бы другой ключ и другую подпись. Формат
     * провода остаётся у владельца — `HmacSignature::sign()` отдаёт hex.
     * Поэтому и зовётся `deriveKey()`, а не `digest(raw: true)` — см. докблок
     * владельца.
     */
    private static function secretKey(string $botToken): string
    {
        return HmacSignature::deriveKey($botToken, self::SECRET_SEED);
    }
}
