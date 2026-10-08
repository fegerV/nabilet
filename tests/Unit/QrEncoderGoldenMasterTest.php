<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Tickets\Domain\Qr\QrCapacityException;
use Nabilet\Modules\Tickets\Domain\Qr\QrEncoder;
use Nabilet\Modules\Tickets\Domain\Qr\QrPngRenderer;
use Nabilet\Tests\Support\TestCase;

/**
 * Свой QR-кодировщик сверяется с независимой реализацией.
 *
 * ПОЧЕМУ ЭТО НЕ ФОРМАЛЬНОСТЬ
 *
 * Ошибка в таблицах Reed-Solomon, в раскладке модулей или в масках даёт
 * картинку, которая ВЫГЛЯДИТ как QR, но не читается сканером. Ни один
 * «структурный» тест этого не поймает: размер верный, формат-биты на месте,
 * PNG валиден. Покупатель узнает о проблеме на входе, когда билет не
 * отсканируется.
 *
 * Поэтому эталон — матрица npm-пакета `qrcode`, той же библиотеки, что рисует
 * QR в браузере покупателя. Требование — ПОБИТОВОЕ совпадение по всем десяти
 * поддерживаемым версиям. Эталон лежит в `tests/Fixtures/qr_golden.json` и
 * пересобирается `node tools/gen-qr-golden.mjs`.
 *
 * Тест нарочно не требует Node в момент прогона: он читает готовый JSON, чтобы
 * оставаться «чистым» и работать без `vendor/` (см. `tools/verify-purity.php`).
 */
final class QrEncoderGoldenMasterTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $fixture = null;

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        if (self::$fixture !== null) {
            return self::$fixture;
        }

        $path = dirname(__DIR__) . '/Fixtures/qr_golden.json';

        $raw = file_get_contents($path);

        if ($raw === false) {
            $this->fail("Эталон не найден: {$path}. Пересоберите: node tools/gen-qr-golden.mjs");
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! isset($decoded['cases']) || ! is_array($decoded['cases'])) {
            $this->fail("Эталон повреждён: {$path}");
        }

        return self::$fixture = $decoded;
    }

    public function testEveryGoldenCaseMatchesTheReferenceMatrixBitForBit(): void
    {
        $cases = $this->fixture()['cases'];

        $this->assertTrue(count($cases) > 0, 'эталон не должен быть пустым');

        foreach ($cases as $case) {
            $payload = (string) $case['payload'];
            $label = sprintf('payload %d байт (v%d)', $case['bytes'], $case['version']);

            $actual = QrEncoder::encode($payload);

            $this->assertSame((int) $case['version'], $actual['version'], "версия не совпала: {$label}");
            $this->assertSame((int) $case['size'], $actual['size'], "размер не совпал: {$label}");

            $expectedRows = $case['rows'];
            $actualRows = [];

            foreach ($actual['rows'] as $row) {
                $line = '';

                foreach ($row as $module) {
                    $line .= $module ? '1' : '0';
                }

                $actualRows[] = $line;
            }

            $this->assertSame(
                $expectedRows,
                $actualRows,
                "матрица не совпала с эталоном: {$label}"
            );
        }
    }

    /**
     * Эталон обязан покрывать ВСЕ поддерживаемые версии.
     *
     * Без этой проверки сужение набора эталонов (например, случайное удаление
     * длинных payload'ов) тихо перестало бы проверять версии 7-10 — а именно
     * там живут таблицы блоков Reed-Solomon второго размера.
     */
    public function testTheFixtureCoversEverySupportedVersion(): void
    {
        $covered = [];

        foreach ($this->fixture()['cases'] as $case) {
            $covered[(int) $case['version']] = true;
        }

        for ($version = 1; $version <= QrEncoder::MAX_VERSION; $version++) {
            $this->assertTrue(
                isset($covered[$version]),
                "версия {$version} не покрыта эталоном — пересоберите фикстуру"
            );
        }
    }

    public function testAPayloadTooLongForTheSupportedVersionsIsRefused(): void
    {
        // Отказ обязателен и типизирован: вызывающий код должен откатиться на
        // текстовый payload, а не отправить письмо без билета.
        $this->assertThrows(
            QrCapacityException::class,
            static fn () => QrEncoder::encode(str_repeat('x', 5000))
        );
    }

    /**
     * PNG проверяется не «начинается с сигнатуры», а разбором: контрольные
     * суммы чанков, размеры из `IHDR` и обратный разбор пикселей в матрицу.
     * Иначе ошибка в упаковке строк (например, порядок битов) осталась бы
     * незамеченной — картинка выглядела бы правдоподобно.
     */
    public function testThePngIsStructurallyValidAndRoundTripsBackToTheMatrix(): void
    {
        $matrix = QrEncoder::encode('NB1.01M46GWH0K1J8N2P4Q6R8S0T2V.token.signature');

        $scale = 3;
        $quietZone = 4;
        $png = QrPngRenderer::render($matrix, $scale, $quietZone);

        $chunks = $this->parsePng($png);

        $this->assertSame(
            ['IHDR', 'IDAT', 'IEND'],
            array_column($chunks, 'type'),
            'состав чанков должен быть ровно IHDR, IDAT, IEND'
        );

        $expectedSide = ($matrix['size'] + 2 * $quietZone) * $scale;
        $header = unpack('Nwidth/Nheight/Cdepth/Ccolor', $chunks[0]['data']);

        $this->assertSame($expectedSide, $header['width']);
        $this->assertSame($expectedSide, $header['height']);
        $this->assertSame(1, $header['depth'], 'ожидается 1 бит на пиксель');
        $this->assertSame(0, $header['color'], 'ожидается оттенки серого');

        $pixels = $this->decodePngPixels($png, $expectedSide);

        // Каждый модуль разворачивается в квадрат $scale × $scale, а вокруг —
        // светлая тихая зона шириной $quietZone модулей.
        foreach ([0, 1, intdiv($matrix['size'], 2), $matrix['size'] - 1] as $my) {
            foreach ([0, 1, intdiv($matrix['size'], 2), $matrix['size'] - 1] as $mx) {
                $x = ($mx + $quietZone) * $scale;
                $y = ($my + $quietZone) * $scale;

                $this->assertSame(
                    $matrix['rows'][$my][$mx],
                    $pixels[$y][$x],
                    "пиксель модуля ({$my},{$mx}) не совпал с матрицей"
                );
            }
        }

        // Тихая зона обязана быть светлой: на тёмном фоне сканер не находит код.
        $this->assertFalse($pixels[0][0], 'левый верхний угол должен быть светлым');
        $this->assertFalse(
            $pixels[$expectedSide - 1][$expectedSide - 1],
            'правый нижний угол должен быть светлым'
        );
    }

    public function testTheDataUriEmbedsTheCodeAndNeverPointsAtAThirdParty(): void
    {
        $uri = QrPngRenderer::dataUri(QrEncoder::encode('NB1.payload.token.signature'));

        $this->assertStringContainsString('data:image/png;base64,', $uri);

        // Главное требование к QR в письме: подписанный payload не уходит
        // наружу. Никакого http-адреса в src быть не может.
        foreach (['http://', 'https://', '//api.', 'qrserver', 'googleapis', 'quickchart'] as $forbidden) {
            $this->assertFalse(
                str_contains(strtolower($uri), $forbidden),
                "data-URI не должен содержать «{$forbidden}»"
            );
        }

        $binary = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);

        $this->assertTrue(is_string($binary), 'тело data-URI должно быть валидным base64');
        $this->assertStringContainsString("\x89PNG\r\n\x1a\n", (string) $binary);
    }

    /**
     * Разбор PNG на чанки с проверкой CRC.
     *
     * @return list<array{type: string, data: string}>
     */
    private function parsePng(string $png): array
    {
        $this->assertStringContainsString("\x89PNG\r\n\x1a\n", $png, 'сигнатура PNG');

        $offset = 8;
        $chunks = [];

        while ($offset < strlen($png)) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $length);
            $crc = unpack('N', substr($png, $offset + 8 + $length, 4))[1];

            $this->assertSame(crc32($type . $data), $crc, "CRC чанка {$type}");

            $chunks[] = ['type' => $type, 'data' => $data];
            $offset += 12 + $length;
        }

        return $chunks;
    }

    /**
     * Обратный разбор: PNG → матрица пикселей.
     *
     * @return list<list<bool>>
     */
    private function decodePngPixels(string $png, int $side): array
    {
        $chunks = $this->parsePng($png);
        $raw = gzuncompress($chunks[1]['data']);

        $this->assertTrue(is_string($raw), 'IDAT должен распаковываться zlib');

        $rowBytes = intdiv($side + 7, 8);
        $pixels = [];

        for ($y = 0; $y < $side; $y++) {
            $line = substr($raw, $y * (1 + $rowBytes), 1 + $rowBytes);

            $this->assertSame("\x00", $line[0], 'фильтр строки должен быть 0');

            $row = [];

            for ($x = 0; $x < $side; $x++) {
                $byte = ord($line[1 + intdiv($x, 8)]);
                // 0 — тёмный, 1 — светлый.
                $row[] = ($byte & (0x80 >> ($x % 8))) === 0;
            }

            $pixels[] = $row;
        }

        return $pixels;
    }
}
