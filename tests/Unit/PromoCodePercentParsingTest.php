<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Pricing\Domain\PromoCodeDefinition;
use Nabilet\Tests\Support\TestCase;

/**
 * «Строка процента → базисные пункты» — одно правило, один владелец.
 *
 * Раньше разбор DECIMAL(5,2) существовал в ДВУХ экземплярах: в
 * `PromoCodeDefinition::fromPercentString()` и внутри `PromoCode::toDefinition()`.
 * Копии успели разойтись в поведении на битом вводе (одна бросала исключение,
 * другая молча отдавала 0%). Теперь парсер живёт в
 * `PromoCodeDefinition::percentToBasisPoints()`, а оба прежних пути его ВЫЗЫВАЮТ.
 *
 * Эти тесты фиксируют математику владельца и то, что `fromPercentString()`
 * действительно делегирует ему, а не повторяет разбор. Роутинг `toDefinition()`
 * проверяется в Feature-тесте (`tests/Feature/Pricing/PromoCodePercentParsingTest.php`),
 * потому что модель требует Eloquent, которого у dependency-free раннера нет.
 */
final class PromoCodePercentParsingTest extends TestCase
{
    public function testPercentToBasisPointsMultipliesWholePartByOneHundred(): void
    {
        // 1% == 100 базисных пунктов, 10% == 1000, 100% == 10000 (потолок).
        $this->assertSame(100, PromoCodeDefinition::percentToBasisPoints('1'));
        $this->assertSame(1000, PromoCodeDefinition::percentToBasisPoints('10'));
        $this->assertSame(10000, PromoCodeDefinition::percentToBasisPoints('100'));
    }

    public function testPercentToBasisPointsKeepsTwoDecimalDigits(): void
    {
        // Два десятичных разряда И ЕСТЬ остаток в базисных пунктах.
        $this->assertSame(1050, PromoCodeDefinition::percentToBasisPoints('10.50'));
        $this->assertSame(1000, PromoCodeDefinition::percentToBasisPoints('10.00'));
        $this->assertSame(5, PromoCodeDefinition::percentToBasisPoints('0.05'));
    }

    public function testPercentToBasisPointsUsesStringMathsNotFloat(): void
    {
        // (float)"10.50" * 100 даёт 1050.0000000000001 на части сборок; строковая
        // математика обязана вернуть ровно 1050, иначе 10,5% станет 10%.
        $this->assertSame(1050, PromoCodeDefinition::percentToBasisPoints('10.50'));
        $this->assertSame(1055, PromoCodeDefinition::percentToBasisPoints('10.55'));
    }

    public function testPercentToBasisPointsTruncatesBeyondTwoDecimals(): void
    {
        // DECIMAL(5,2) третий знак не хранит, но если он придёт — усечение, не округление.
        $this->assertSame(1055, PromoCodeDefinition::percentToBasisPoints('10.555'));
    }

    public function testPercentToBasisPointsAcceptsLeadingDotAndZero(): void
    {
        $this->assertSame(50, PromoCodeDefinition::percentToBasisPoints('.5'));
        $this->assertSame(0, PromoCodeDefinition::percentToBasisPoints('0'));
        $this->assertSame(0, PromoCodeDefinition::percentToBasisPoints('0.00'));
    }

    public function testPercentToBasisPointsRejectsEmptyAndGarbage(): void
    {
        $this->assertThrows(DomainRuleViolation::class, static fn () => PromoCodeDefinition::percentToBasisPoints(''));
        $this->assertThrows(DomainRuleViolation::class, static fn () => PromoCodeDefinition::percentToBasisPoints('abc'));
        $this->assertThrows(DomainRuleViolation::class, static fn () => PromoCodeDefinition::percentToBasisPoints('-5'));
    }

    public function testFromPercentStringDelegatesToTheSameParser(): void
    {
        // Оба пути обязаны дать одинаковые базисные пункты — иначе домен,
        // построенный из модели, разойдётся с доменом из строки.
        foreach (['10.50', '10', '0.05', '100.00', '0.5'] as $percent) {
            $this->assertSame(
                PromoCodeDefinition::percentToBasisPoints($percent),
                PromoCodeDefinition::fromPercentString($percent, 'X')->valuePercentBasisPoints,
                sprintf('fromPercentString("%s")', $percent)
            );
        }
    }
}
