<?php

declare(strict_types=1);

namespace Nabilet\Modules\Matrix\Domain;

use Nabilet\Core\Support\Money;

/**
 * Тексты административных уведомлений для Matrix-комнаты.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС, А НЕ СБОРКА СТРОКИ В ОБСЕРВЕРЕ
 *   Сборка текста — это решение о том, ЧТО администратор увидит в Element X.
 *   Если она живёт в обсервере, то тестируется только через базу и очередь, а
 *   форматирование суммы, порядок строк и поведение при пустом названии
 *   мероприятия остаются непроверенными. Здесь это чистая функция от массива:
 *   её видно в `php tests/run.php` без `vendor/`, без MySQL и без HTTP.
 *
 * ФОРМА ВХОДА — ПЛОСКИЙ МАССИВ, А НЕ МОДЕЛЬ
 *   Домен не должен знать про Eloquent (см. `tools/verify-purity.php`), поэтому
 *   принимается не `Order`, а его значимые поля. Заодно это делает тексты
 *   устойчивыми к изменениям модели: обсервер отвечает за извлечение полей,
 *   класс — за то, как они выглядят.
 */
final class MatrixNotice
{
    /**
     * Уведомление об оплаченном заказе.
     *
     * @param  array{
     *     order_number: string,
     *     total_minor: int,
     *     currency: string,
     *     event_title?: string|null,
     *     customer?: string|null,
     * }  $order
     */
    public static function orderPaid(array $order): string
    {
        $lines = [
            'Новый оплаченный заказ',
            'Номер: ' . $order['order_number'],
            'Сумма: ' . self::money($order['total_minor'], $order['currency']),
        ];

        $eventTitle = trim((string) ($order['event_title'] ?? ''));

        if ($eventTitle !== '') {
            $lines[] = 'Мероприятие: ' . $eventTitle;
        }

        $customer = trim((string) ($order['customer'] ?? ''));

        if ($customer !== '') {
            $lines[] = 'Покупатель: ' . $customer;
        }

        return implode("\n", $lines);
    }

    /**
     * Тестовое сообщение — отправляется кнопкой «Проверить» в админке.
     *
     * Намеренно отличается от боевого: администратор должен видеть в Element X,
     * что это проверка, а не настоящая продажа.
     */
    public static function test(): string
    {
        return "Тестовое сообщение NABILET.\nКанал Matrix настроен и работает.";
    }

    /**
     * Сумма в минорных единицах → «1 500,00 ₽».
     *
     * `Money::format()` — единственный форматтер денег в проекте (см.
     * `Nabilet\Core\Support\Money`). Своё `number_format` здесь разошлось бы с
     * чеками и письмами: в одном месте «1 500,00 ₽», в другом «1500.00».
     */
    private static function money(int $minorUnits, string $currency): string
    {
        $code = trim($currency) === '' ? 'RUB' : trim($currency);

        return Money::of($minorUnits, $code)->format();
    }
}
