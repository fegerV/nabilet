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
    public function test_qr_is_decoded_from_a_printed_pdf(): void
    {
        $chrome = (string) (getenv('TICKET_PDF_CHROME_BIN') ?: '');
        if ($chrome === '' || ! is_file($chrome)) {
            $this->markTestSkipped('Chrome для печати PDF не настроен (TICKET_PDF_CHROME_BIN)');
        }
        $decoder = dirname(__DIR__, 2) . '/Support/decode_qr_pdf.py';
        if (! is_file($decoder)) {
            $this->markTestSkipped('PDF-декодер отсутствует');
        }

        $payload = 'NB1.01PDFPUBLICID.token.signature';
        $png = QrPngRenderer::render(QrEncoder::encode($payload), 10, 4);
        $dir = sys_get_temp_dir() . '/nabilet-pdf-qr-' . bin2hex(random_bytes(5));
        mkdir($dir, 0775, true);
        $html = $dir . '/ticket.html';
        $pdf = $dir . '/ticket.pdf';

        try {
            file_put_contents($html, '<html><body style="margin:0"><img width="600" height="600" src="data:image/png;base64,'
                . base64_encode($png) . '"></body></html>');
            $process = new \Symfony\Component\Process\Process([
                $chrome, '--headless', '--no-sandbox', '--disable-gpu', '--no-pdf-header-footer',
                '--user-data-dir=' . $dir . '/profile', '--print-to-pdf=' . $pdf, 'file:///' . str_replace('\\', '/', $html),
            ]);
            $process->setTimeout(60);
            $process->run();
            $this->assertFileExists($pdf, 'Chrome не создал PDF: ' . $process->getErrorOutput());

            $decoded = [];
            $code = 1;
            exec('python ' . escapeshellarg($decoder) . ' ' . escapeshellarg($pdf), $decoded, $code);
            if ($code === 3 || $code === 127) {
                $this->markTestSkipped('Python/pymupdf/OpenCV недоступны');
            }
            $this->assertSame(0, $code, 'QR из PDF не распознан');
            $this->assertSame($payload, implode("\n", $decoded));
        } finally {
            foreach ([$html, $pdf] as $file) {
                @unlink($file);
            }
            foreach (glob($dir . '/profile/*') ?: [] as $tmp) {
                @unlink($tmp);
            }
            @rmdir($dir . '/profile');
            @rmdir($dir);
        }
    }

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
