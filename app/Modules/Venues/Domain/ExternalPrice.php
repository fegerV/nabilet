<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;

/**
 * Цена, прочитанная из ЧУЖОЙ страницы (импорт площадки с Яндекс Афиши).
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС. Один и тот же вопрос — «сколько стоит событие» — в
 * `VenueImportController` решался двумя разными способами, в двух методах:
 *
 *   `extractTicket()`  — Apollo-стейт отдаёт КОПЕЙКИ, код делал
 *                        `(int) (((int) $m[3]) / 100)` — вещественное деление
 *                        с отбрасыванием остатка: 2999 копеек превращались в
 *                        29 рублей вместо 30;
 *   `extractEvent()`   — JSON-LD отдаёт РУБЛИ десятичным числом, код делал
 *                        `(float) $json['offers']['lowPrice']`.
 *
 * То есть одна величина приезжала в двух единицах, приводилась к двум разным
 * типам (int и float) и наружу уходила в двух разных масштабах — администратор
 * видел «цены 29–30» там, где на странице написано «29,99». Ни одной ошибки при
 * этом не возникало: обе ветки отвечали 200.
 *
 * ПОЧЕМУ ВЫХОД — MINOR UNITS. В проекте деньги хранятся и передаются целыми
 * minor units (ТЗ §3), и витрина форматирует их через `money()` на клиенте.
 * Импорт был единственным местом, где наружу уходили рубли, — то есть
 * приводить к общему масштабу надо было импорт, а не наоборот.
 *
 * ПОЧЕМУ `Money`, А НЕ СВОЯ АРИФМЕТИКА. Разбор десятичной строки и правило
 * «никогда не float» уже имеют владельца (`Core\Support\Money`). Здесь остаётся
 * только то, чего у владельца нет: знание, что внешний источник присылает
 * ДВА формата, и политика «нераспознанная цена — это отсутствие цены, а не
 * исключение наружу»: страница чужого сайта не должна ронять предпросмотр
 * пятисоткой.
 *
 * Чистый класс: `Money` и `DomainRuleViolation` фреймворка не требуют, поэтому
 * проверяется dependency-free раннером.
 */
final class ExternalPrice
{
    /**
     * Верхняя граница разумного: 10^15 minor units. Всё, что длиннее, — не цена,
     * а случайно пойманная строка (например, `id` из стейта), и `(int)` на ней
     * уже переполнялся бы.
     */
    private const MAX_DIGITS = 15;

    private function __construct(private readonly Money $money)
    {
    }

    /**
     * Цена уже в minor units (Apollo-стейт Яндекс.Афиши: `price.min/max`).
     *
     * Только цифры: `"2999"` или `2999`. Знак и разделители здесь означали бы,
     * что формат источника изменился, — тогда лучше «цены нет», чем `(int)`
     * от мусора.
     */
    public static function fromMinorUnits(int|string|null $minor, ?string $currency = null): ?self
    {
        if ($minor === null) {
            return null;
        }

        $digits = is_int($minor) ? (string) $minor : trim($minor);

        if (preg_match('/^\d{1,' . self::MAX_DIGITS . '}$/', $digits) !== 1) {
            return null;
        }

        return new self(Money::of((int) $digits, self::currencyOr($currency)));
    }

    /**
     * Цена в major units десятичной записью (JSON-LD `offers.lowPrice`).
     *
     * Принимает и число, и строку: JSON-LD от разных сайтов приходит в обоих
     * видах, а `2500` и `"2500.00"` — одна и та же цена. Разбор — строковый,
     * внутри `Money::fromDecimal()`: `(float)` на деньгах даёт дрейф на границе
     * точности, и именно от него этот класс избавляет.
     *
     * Отрицательная цена — отказ: скидка выражается полем скидки, а не знаком
     * суммы, и `-100` в предпросмотре означало бы «источник прислал не то».
     */
    public static function fromMajorAmount(int|float|string|null $major, ?string $currency = null): ?self
    {
        if ($major === null) {
            return null;
        }

        $decimal = is_string($major) ? trim($major) : (string) $major;

        if ($decimal === '') {
            return null;
        }

        try {
            $money = Money::fromDecimal($decimal, self::currencyOr($currency));
        } catch (DomainRuleViolation) {
            // Источник прислал не число (или число в экспоненциальной записи) —
            // это «цены нет», а не сбой импорта.
            return null;
        }

        return $money->isNegative() ? null : new self($money);
    }

    public function minorUnits(): int
    {
        return $this->money->minorUnits();
    }

    public function currency(): string
    {
        return $this->money->currency();
    }

    /**
     * Валюта в каноническом виде: верхний регистр, `RUB` по умолчанию.
     *
     * Одно место на оба формата. Раньше `extractTicket()` делал
     * `mb_strtoupper()`, а `extractEvent()` отдавал строку как есть, поэтому
     * `rub` и `RUB` уезжали в один и тот же ответ.
     */
    private static function currencyOr(?string $currency): string
    {
        $normalized = $currency === null ? '' : mb_strtoupper(trim($currency));

        return $normalized === '' ? 'RUB' : $normalized;
    }
}
