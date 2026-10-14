<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Domain;

/**
 * Код приглашения: генерация, нормализация, проверка формы.
 *
 * ЗАЧЕМ КЛАСС, А НЕ `Str::random()` НА МЕСТЕ
 *   Код — часть ссылки `/r/{code}`, то есть то, что диктуют голосом и
 *   переписывают вручную. Генерация со знакомыми вызовами (`random_int`,
 *   `strtoupper`) разошлась бы по трём местам — сервис, который выдаёт код,
 *   сид и тест — и каждый добавил бы свой алфавит. Здесь алфавит один, и он
 *   исключает визуально похожие символы: `0`/`O` и `1`/`I` отличаются только
 *   шрифтом, и код с ними теряется при пересылке в мессенджер.
 *
 * ПОЧЕМУ 16 СИМВОЛОВ, А НЕ 8
 *   Алфавит — 32 символа (5 бит на символ), поэтому 16 символов дают 80 бит
 *   энтропии. Перебор под `UNIQUE (code)` бессмыслен, а «угадать чужую ссылку»
 *   тем более: приглашение без покупки не начисляет ничего.
 *
 * ФРЕЙМВОРК-ФРИ (`tools/verify-purity.php`): только PHP. Никаких фасадов,
 * никакой базы — правила ссылки не должны зависеть от того, собрано ли
 * приложение.
 */
final class ReferralCode
{
    /**
     * Алфавит без визуально похожих пар: нет `0` (как `O`), `1` (как `I`),
     * и всего 32 символа — пять бит на символ ровно.
     */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 16;

    private function __construct()
    {
        // Утилита правил: экземпляр не нужен, а `new ReferralCode()`
        // сбивал бы с толку — у ссылки нет состояния.
    }

    /** Новый код. `random_int` — CSPRNG: код не должен угадываться по времени. */
    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Привести введённое к форме, в которой код хранится и ищется.
     *
     * Верхний регистр и обрезка пробелов: ссылку копируют из письма и
     * мессенджеров, и ` abc123 ` — это `ABC123`, а не другой код. Символы вне
     * алфавита (например, кириллица при ручном вводе) не «чинятся»: такой код
     * просто не найдутся — см. `isWellFormed()`.
     */
    public static function normalize(string $raw): string
    {
        return strtoupper(trim($raw));
    }

    /** Похоже ли на код: длина, алфавит, регистр. Не обращается к базе. */
    public static function isWellFormed(string $code): bool
    {
        if (strlen($code) !== self::LENGTH) {
            return false;
        }

        for ($i = 0; $i < self::LENGTH; $i++) {
            if (!str_contains(self::ALPHABET, $code[$i])) {
                return false;
            }
        }

        return true;
    }
}
