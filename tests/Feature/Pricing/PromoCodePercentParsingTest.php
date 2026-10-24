<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Orders\Models\PromoCode;
use Nabilet\Modules\Pricing\Domain\PromoCodeDefinition;
use Tests\TestCase;

/**
 * `PromoCode::toDefinition()` не должен заводить ВТОРУЮ копию разбора процента.
 *
 * Владелец правила — `PromoCodeDefinition::percentToBasisPoints()`; модель
 * обязана его ВЫЗЫВАТЬ. Раньше `toDefinition()` держал собственный `preg_match`
 * плюс ту же арифметику `whole*100 + fraction`, и копия успела разойтись: на
 * битом вводе `fromPercentString()` бросал исключение, а `toDefinition()` молча
 * отдавал 0% (скидка, которая ничего не скидывает).
 *
 * Тест сравнивает домен, построенный из модели, с доменом, построенным из той же
 * строки напрямую: разойдись они — покупатель увидел бы одну скидку, а движок
 * цен посчитал другую.
 */
final class PromoCodePercentParsingTest extends TestCase
{
    private function model(string $percent): PromoCode
    {
        return new PromoCode([
            'code' => 'X',
            'discount_type' => 'percent',
            'value_amount' => 0,
            'value_percent' => $percent,
            'currency' => 'RUB',
            'scope' => 'all',
            'status' => 'active',
        ]);
    }

    public function testToDefinitionAgreesWithTheSharedParser(): void
    {
        foreach (['10.50', '10', '0.05', '100.00', '0.5'] as $percent) {
            $this->assertSame(
                PromoCodeDefinition::fromPercentString($percent, 'X')->valuePercentBasisPoints,
                $this->model($percent)->toDefinition()->valuePercentBasisPoints,
                sprintf('percent "%s"', $percent)
            );
        }
    }

    public function testToDefinitionRejectsAnUnparseablePercent(): void
    {
        // Fail-closed: битый процент в модели теперь уходит в общий парсер и
        // бросает, а не превращается молча в 0%.
        $this->expectException(DomainRuleViolation::class);
        $this->model('not-a-number')->toDefinition();
    }
}
