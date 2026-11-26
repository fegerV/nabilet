<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use Nabilet\Modules\Tickets\Domain\Qr\QrEncoder;
use Nabilet\Modules\Tickets\Domain\Qr\QrPngRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Независимое подтверждение читаемости QR готовой картинки.
 *
 * Golden-master проверяет матрицу нашим же алгоритмом; здесь картинку
 * читает OpenCV извне. Это не «похоже на QR», а фактически декодированный текст.
 */
final class TicketQrDecodeTest extends TestCase
{
    public function test_generated_png_is_decoded_by_an_external_reader(): void
    {
        $payload = 'NB1.01JTESTPUBLICID.token.signature';
        $png = QrPngRenderer::render(QrEncoder::encode($payload), 8, 6);
        $this->assertStringStartsWith("\x89PNG", $png);

        $path = tempnam(sys_get_temp_dir(), 'nabilet-qr-') ?: null;
        $script = dirname(__DIR__, 2) . '/Support/decode_qr.py';
        if ($path === null || ! is_file($script)) {
            $this->markTestSkipped('Не удалось подготовить временный файл/декодер');
        }
        file_put_contents($path, $png);

        $decoded = [];
        $code = 1;
        try {
            exec('python ' . escapeshellarg($script) . ' ' . escapeshellarg($path), $decoded, $code);
        } finally {
            @unlink($path);
        }

        if ($code === 3 || $code === 127) {
            $this->markTestSkipped('Python/OpenCV недоступны — внешний декодер не запущен');
        }
        $this->assertSame(0, $code, 'Внешний декодер не смог прочитать QR');
        $this->assertSame($payload, implode("\n", $decoded));
    }
}
