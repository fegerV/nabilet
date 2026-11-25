<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Tickets\Domain\Barcode\Code128Encoder;
use Nabilet\Tests\Support\TestCase;

/**
 * Локальный CODE128-B для билета: никаких сторонних сервисов и заглушек.
 *
 * Проверяем не «картинка есть», а сам символ: старт/стоп, контрольную сумму,
 * тихую зону и детерминированность. Контролёр НЕ принимает штрихкод как
 * пропуск — это информационный элемент билета.
 */
final class Code128EncoderTest extends TestCase
{
    public function test_encodes_known_value_with_code128_b_checksum(): void
    {
        // START-B(104), A(33), B(34), C(35), checksum = (104+33+68+105)%103 = 1, STOP.
        $expected = '211214' . '111323' . '131123' . '131321' . '222122' . '2331112';

        $this->assertSame($expected, Code128Encoder::encode('ABC'));
    }

    public function test_has_a_quiet_zone_and_keeps_proportions(): void
    {
        $bars = Code128Encoder::bars('TICKET-0001');
        $firstOffset = $bars[0][0];

        $this->assertSame(10, $firstOffset, 'тихая зона обязана быть не менее 10 модулей');
        $this->assertSame(
            Code128Encoder::modules('TICKET-0001'),
            Code128Encoder::modules('TICKET-0001'),
        );
        $svg = Code128Encoder::svg('TICKET-0001', 240, 60);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertFalse(str_contains($svg, 'TICKET-0001'), 'значение не выводится в SVG как текст');
    }

    public function test_rejects_empty_values_and_strips_non_ascii(): void
    {
        $this->assertSame(Code128Encoder::encode(' ABC'), Code128Encoder::encode('АБВ ABC'));

        try {
            Code128Encoder::encode('ЖЖЖ');
            $this->fail('Пустой символ не должен кодироваться');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }
}
