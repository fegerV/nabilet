<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\Money;
use Nabilet\Tests\Support\TestCase;

/**
 * Форматирование суммы заказа в письме — СОБСТВЕННОСТЬ Nabilet\Core\Support\Money,
 * а не ручной копии в OrderNotificationData::money().
 *
 * ЧТО БЫЛО. OrderNotificationData::money() собирала строку через
 * `number_format(((int) $order->total_amount) / 100, 2, ',', ' ')` и match по
 * валюте — ровно то, что Money::format('ru_RU') уже делает («2 500,00 ₽»).
 * Две копии одного правила молча расходятся: при смене отображения валюты в
 * Money письмо продолжило бы форматировать по-своему.
 *
 * Сторож не ограничивается «класс существует» — он проверяет, что файл
 * OrderNotificationData больше не содержит ручного number_format() и что его
 * money() делегирует Money::of(...)->format('ru_RU') (а не копирует логику).
 * Плюс поведенчески зафиксирован контракт Money::format, включая границу
 * 0,29 ₽ (строковая математика Money без дрейфа float). Вернуть копию, не
 * сломав тест, нельзя.
 */
final class NotificationMoneyFormatSingleOwnerTest extends TestCase
{
    private const TARGET_FILE = 'app/Modules/Notifications/Services/OrderNotificationData.php';

    private function targetSource(): string
    {
        $root = dirname(__DIR__, 2);

        return (string) file_get_contents($root . '/' . self::TARGET_FILE);
    }

    public function test_target_delegates_to_money_and_has_no_hand_rolled_formatting(): void
    {
        $src = $this->targetSource();

        // Никакого ручного числового форматирования суммы в этом файле.
        $this->assertFalse(
            (bool) preg_match('/number_format\s*\(/', $src),
            'В OrderNotificationData найден ручной number_format() — форматирование суммы должно идти через Money::format().'
        );

        // money() собирает сумму через единственного владельца правила, а не копию.
        $this->assertStringContainsString('Money::of(', $src);
        $this->assertStringContainsString("->format('ru_RU')", $src);
    }

    public function test_money_format_contract_is_locked(): void
    {
        // Контракт отображения Money — источник истины для письма заказа.
        // Совпадает с тем, что раньше выдавала ручная копия в money() для RUB.
        $this->assertSame('50 000,00 ₽', Money::of(5000000, 'RUB')->format('ru_RU'));
        $this->assertSame('25 000,00 ₽', Money::of(2500000, 'RUB')->format('ru_RU'));
        $this->assertSame('125,00 ₽', Money::of(12500, 'RUB')->format('ru_RU'));
        $this->assertSame('0,29 ₽', Money::of(29, 'RUB')->format('ru_RU'));
        $this->assertSame('0,00 ₽', Money::of(0, 'RUB')->format('ru_RU'));
    }
}
