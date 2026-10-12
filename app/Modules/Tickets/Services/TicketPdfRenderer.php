<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Services;

use Illuminate\Support\Facades\Log;
use Nabilet\Modules\Tickets\Domain\Qr\QrEncoder;
use Nabilet\Modules\Tickets\Domain\Qr\QrPngRenderer;
use Nabilet\Modules\Tickets\Models\Ticket;

/**
 * Рендер билета в PDF для вложения в письмо покупателю.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ PDF
 *
 * HTML-письмо несёт только палитру макета (см. `TicketPalette`): почтовые
 * клиенты не поддерживают `position:absolute`, поэтому полный холст конструктора
 * (`template_json`) в письмо не попадает. Покупатель же должен получить билет
 * именно в том виде, в каком его собрал администратор, — в том числе раскладку
 * QR, текстовых блоков и фигур. PDF поддерживает абсолютное позиционирование,
 * поэтому `template_json.elements` переводится в абсолютно позиционированную
 * вёрстку один-в-один.
 *
 * КАК РЕНДЕРИМ
 *
 * Строим самодостаточный HTML (inline-стили, абсолютные координаты из
 * `template_json`, QR вложен как `data:image/png;base64,…` — локально, без
 * сторонних сервисов) и отдаём его headless-Chrome (`--print-to-pdf`). Chrome
 * корректно отрисовывает кириллицу, шрифты и встроенный QR, а бинарь уже есть
 * в окружении. Это минимальный шаг; в проде вместо Chrome можно подставить
 * серверную PDF-библиотеку (dompdf и т.п.) — интерфейс `render()` от этого не
 * меняется.
 *
 * ОТКАЗОУСТОЙЧИВОСТЬ
 *
 * Если Chrome недоступен (нет бинаря / не удалось запустить), `render()`
 * возвращает `null`. `TicketPurchasedMail` ловит это и отправляет письмо БЕЗ
 * вложения — билет в виде HTML всё равно уходит. Письмо не должно падать из-за
 * картинки (см. тот же принцип в `TicketPalette`).
 */
final class TicketPdfRenderer
{
    public function __construct(
        private readonly TicketGeneratorService $generator,
        private readonly TicketTemplateResolver $resolver,
    ) {}

    /**
     * Полный путь: резолв макета мероприятия → HTML → PDF.
     *
     * @return string|null PDF-байты или null, если рендер не удался.
     */
    public function render(Ticket $ticket): ?string
    {
        $data = $this->generator->generateTicketData($ticket);

        $template = $this->resolver->resolveForTicket($ticket);
        $config = is_array($template?->template_json) ? $template->template_json : null;

        if (! is_array($config) || ! is_array($config['elements'] ?? null) || $config['elements'] === []) {
            $config = $this->defaultConfig($data);
        }

        return $this->buildPdf($config, $data);
    }

    /**
     * Рендер готовой конфигурации холста (для тестов и прямого вызова).
     *
     * @param  array<string, mixed>  $config  ['backgroundColor'=>?,'width'=>?,'height'=>?,'elements'=>[]]
     * @param  array<string, mixed>  $data    вывод TicketGeneratorService::generateTicketData
     */
    public function renderFromConfig(array $config, array $data): ?string
    {
        return $this->buildPdf($config, $data);
    }

    /**
     * HTML-вёрстка билета без конвертации в PDF.
     *
     * Полезно для отладки и для потенциального встраивания билета в тело письма
     * (inline-CID) — картинка-билет в HTML-теле видна сразу, без скачивания
     * вложения. PDF остаётся в роли печатной копии.
     */
    public function renderHtml(Ticket $ticket): string
    {
        $data = $this->generator->generateTicketData($ticket);
        $template = $this->resolver->resolveForTicket($ticket);
        $config = is_array($template?->template_json) ? $template->template_json : null;

        if (! is_array($config) || ! is_array($config['elements'] ?? null) || $config['elements'] === []) {
            $config = $this->defaultConfig($data);
        }

        return $this->buildHtml($config, $data);
    }

    /**
     * HTML по произвольной конфигурации холста (для тестов).
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public function renderHtmlFromConfig(array $config, array $data): string
    {
        return $this->buildHtml($config, $data);
    }

    /**
     * Строит HTML и конвертирует в PDF через headless-Chrome.
     */
    private function buildPdf(array $config, array $data): ?string
    {
        $html = $this->buildHtml($config, $data);
        if ($html === '') {
            return null;
        }

        return $this->htmlToPdf($html);
    }

    /**
     * Вёрстка билета из конфигурации холста.
     */
    private function buildHtml(array $config, array $data): string
    {
        $width = (int) ($config['width'] ?? 400);
        $height = (int) ($config['height'] ?? 600);
        $bg = $this->safeColor($config['backgroundColor'] ?? null, '#ffffff');

        $elements = '';
        foreach (($config['elements'] ?? []) as $el) {
            $elements .= $this->renderElement($el, $data);
        }

        // Если в макете нет QR-элемента, добавляем его сами — билет без
        // сканируемого кода бессмысленен.
        if (! $this->hasQr($config)) {
            $size = 140;
            $x = (int) (($width - $size) / 2);
            $y = $height - $size - 40;
            $elements .= $this->qrElement(['x' => $x, 'y' => $y, 'width' => $size, 'height' => $size], $data);
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<style>
  @page { size: {$width}px {$height}px; margin: 0; }
  html, body { margin: 0; padding: 0; }
  #ticket {
    position: relative;
    width: {$width}px;
    height: {$height}px;
    background: {$bg};
    overflow: hidden;
    font-family: 'Segoe UI', 'DejaVu Sans', Arial, sans-serif;
    box-sizing: border-box;
  }
</style>
</head>
<body>
  <div id="ticket">{$elements}</div>
</body>
</html>
HTML;
    }

    /**
     * Один элемент холста → HTML-кусок.
     *
     * @param  mixed  $el
     */
    private function renderElement(mixed $el, array $data): string
    {
        if (! is_array($el)) {
            return '';
        }

        $type = is_string($el['type'] ?? null) ? $el['type'] : 'rectangle';
        $x = (float) ($el['x'] ?? 0);
        $y = (float) ($el['y'] ?? 0);

        return match ($type) {
            'text' => $this->textElement($el, $data),
            'qr' => $this->qrElement($el, $data),
            'rectangle' => $this->shapeElement($el, $x, $y, '0'),
            'circle' => $this->shapeElement($el, $x, $y, '50%'),
            'line' => $this->lineElement($el, $x, $y),
            'image' => $this->placeholderElement($el, $x, $y, 'IMG'),
            'barcode' => $this->placeholderElement($el, $x, $y, '||  ||  ||'),
            default => $this->shapeElement($el, $x, $y, '0'),
        };
    }

    /**
     * @param  array<string, mixed>  $el
     */
    private function textElement(array $el, array $data): string
    {
        $w = (float) ($el['width'] ?? 200);
        $h = (float) ($el['height'] ?? 30);
        $fs = (float) ($el['fontSize'] ?? 14);
        $weight = ($el['fontWeight'] ?? 'normal') === 'bold' ? 'bold' : 'normal';
        $color = $this->safeColor($el['color'] ?? null, '#111827');
        $content = $this->renderText((string) ($el['content'] ?? ''), $data);

        return sprintf(
            '<div style="position:absolute;left:%1$Fpx;top:%2$Fpx;width:%3$Fpx;min-height:%4$Fpx;'
            . 'font-size:%5$Fpx;font-weight:%6$s;color:%7$s;line-height:1.2;white-space:pre-wrap;'
            . 'word-break:break-word;overflow:hidden;">%8$s</div>',
            $el['x'] ?? 0, $el['y'] ?? 0, $w, $h, $fs, $weight, $color, $content
        );
    }

    /**
     * @param  array<string, mixed>  $el
     */
    private function qrElement(array $el, array $data): string
    {
        $w = (float) ($el['width'] ?? 140);
        $h = (float) ($el['height'] ?? 140);

        $uri = $data['qr_data_uri'] ?? '';
        if (! is_string($uri) || $uri === '') {
            // Фолбэк: строим QR локально, если вдруг data-uri не пришёл.
            try {
                $uri = QrPngRenderer::dataUri(QrEncoder::encode((string) ($data['qr_payload'] ?? '')));
            } catch (\Throwable) {
                $uri = '';
            }
        }

        $img = $uri !== ''
            ? sprintf('<img src="%s" style="width:%Fpx;height:%Fpx;display:block;">', htmlspecialchars($uri), $w, $h)
            : '';

        // В PDF несём только сканируемый QR: читаемый payload дублируется в теле
        // письма (текстовый фолбэк), поэтому здесь подпись не нужна и не занимает
        // место над футером.
        return sprintf(
            '<div style="position:absolute;left:%Fpx;top:%Fpx;width:%Fpx;">%s</div>',
            $el['x'] ?? 0, $el['y'] ?? 0, $w, $img
        );
    }

    /**
     * @param  array<string, mixed>  $el
     */
    private function shapeElement(array $el, float $x, float $y, string $radius): string
    {
        $w = (float) ($el['width'] ?? 100);
        $h = (float) ($el['height'] ?? 100);
        $fill = $this->safeColor($el['fill'] ?? null, 'transparent');
        $stroke = $this->safeColor($el['stroke'] ?? null, '');
        $sw = (float) ($el['strokeWidth'] ?? (($el['stroke'] ?? null) ? 1 : 0));

        $border = $stroke !== '' && $sw > 0
            ? sprintf('border:%Fpx solid %s;', $sw, $stroke)
            : 'border:0;';

        return sprintf(
            '<div style="position:absolute;left:%Fpx;top:%Fpx;width:%Fpx;height:%Fpx;'
            . 'background:%s;border-radius:%s;%sbox-sizing:border-box;"></div>',
            $x, $y, $w, $h, $fill, $radius, $border
        );
    }

    /**
     * @param  array<string, mixed>  $el
     */
    private function lineElement(array $el, float $x, float $y): string
    {
        $w = (float) ($el['width'] ?? 200);
        $h = (float) ($el['height'] ?? 200);
        $x2 = (float) ($el['x2'] ?? ($x + $w));
        $y2 = (float) ($el['y2'] ?? $y);
        $sw = (float) ($el['strokeWidth'] ?? (($el['stroke'] ?? null) ? 1 : 0));
        $stroke = $this->safeColor($el['stroke'] ?? $el['color'] ?? null, '#111827');
        $canvasW = max($w, $x2 - $x, 1) + 4;
        $canvasH = max($h, $y2 - $y, 1) + 4;

        return sprintf(
            '<svg style="position:absolute;left:0;top:0;width:%Fpx;height:%Fpx;overflow:visible;" '
            . 'viewBox="0 0 %F %F"><line x1="%F" y1="%F" x2="%F" y2="%F" stroke="%s" '
            . 'stroke-width="%F" /></svg>',
            $canvasW, $canvasH, $canvasW, $canvasH, $x, $y, $x2, $y2, $stroke, max($sw, 1)
        );
    }

    /**
     * @param  array<string, mixed>  $el
     */
    private function placeholderElement(array $el, float $x, float $y, string $label): string
    {
        $w = (float) ($el['width'] ?? 100);
        $h = (float) ($el['height'] ?? 100);

        return sprintf(
            '<div style="position:absolute;left:%Fpx;top:%Fpx;width:%Fpx;height:%Fpx;'
            . 'display:flex;align-items:center;justify-content:center;background:#eef2f7;'
            . 'border:1px dashed #cbd5e1;color:#64748b;font-size:11px;">%s</div>',
            $x, $y, $w, $h, htmlspecialchars($label, ENT_QUOTES)
        );
    }

    /**
     * Подстановка {{переменных}} в текст элемента.
     */
    private function renderText(string $content, array $data): string
    {
        $flat = [
            'event.name' => (string) ($data['event_name'] ?? ''),
            'event.date' => (string) ($data['event_date'] ?? ''),
            'event.time' => '',
            'event.venue' => (string) ($data['venue_name'] ?? ''),
            'ticket.number' => (string) ($data['ticket_number'] ?? ''),
            'ticket.holder' => (string) ($data['customer_name'] ?? ''),
            'seat.row' => '',
            'seat.number' => '',
            'seat.info' => (string) ($data['seats'] ?? ''),
            'order.id' => (string) ($data['order_id'] ?? ''),
        ];

        $text = preg_replace_callback('/\{\{([\w.]+)\}\}/', static function (array $m) use ($flat): string {
            return $flat[$m[1]] ?? $m[0];
        }, $content);

        return htmlspecialchars((string) $text, ENT_QUOTES);
    }

    private function hasQr(array $config): bool
    {
        foreach (($config['elements'] ?? []) as $el) {
            if (is_array($el) && ($el['type'] ?? null) === 'qr') {
                return true;
            }
        }

        return false;
    }

    /**
     * Цвет из макета: только hex/именованные, иначе значение по умолчанию.
     * Защита от разрыва атрибута стиля (как в `TicketPalette`).
     */
    private function safeColor(mixed $value, string $fallback): string
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        $value = trim($value);

        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1) {
            return strtolower($value);
        }

        if (in_array(strtolower($value), ['black', 'white', 'transparent', 'inherit', 'none'], true)) {
            return strtolower($value);
        }

        return $fallback;
    }

    /**
     * Макет по умолчанию (мероприятию не назначен шаблон). Вертикальный 400×600,
     * с фирменной плашкой из палитры — чтобы у билета был узнаваемый вид даже
     * без конструктора.
     */
    private function defaultConfig(array $data): array
    {
        $accent = (string) ($data['template']['accent_color'] ?? '#0f766e');
        $bg = (string) ($data['template']['background_color'] ?? '#f9fafb');

        return [
            'backgroundColor' => $bg,
            'width' => 400,
            'height' => 600,
            'elements' => [
                ['type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 400, 'height' => 130, 'fill' => $accent],
                ['type' => 'text', 'x' => 24, 'y' => 26, 'width' => 352, 'height' => 56, 'fontSize' => 22, 'fontWeight' => 'bold', 'color' => '#ffffff', 'content' => '{{event.name}}'],
                ['type' => 'text', 'x' => 24, 'y' => 84, 'width' => 352, 'height' => 22, 'fontSize' => 14, 'color' => '#ffffff', 'content' => '{{event.date}}'],
                ['type' => 'text', 'x' => 24, 'y' => 108, 'width' => 352, 'height' => 18, 'fontSize' => 12, 'color' => '#ffffff', 'content' => '{{event.venue}}'],
                ['type' => 'text', 'x' => 24, 'y' => 150, 'width' => 352, 'height' => 18, 'fontSize' => 11, 'color' => '#6b7280', 'content' => 'БИЛЕТ ДЛЯ'],
                ['type' => 'text', 'x' => 24, 'y' => 168, 'width' => 352, 'height' => 26, 'fontSize' => 18, 'fontWeight' => 'bold', 'color' => '#111827', 'content' => '{{ticket.holder}}'],
                ['type' => 'text', 'x' => 24, 'y' => 206, 'width' => 352, 'height' => 18, 'fontSize' => 11, 'color' => '#6b7280', 'content' => 'МЕСТА'],
                ['type' => 'text', 'x' => 24, 'y' => 224, 'width' => 352, 'height' => 24, 'fontSize' => 16, 'fontWeight' => 'bold', 'color' => '#0f766e', 'content' => '{{seat.info}}'],
                ['type' => 'text', 'x' => 24, 'y' => 262, 'width' => 200, 'height' => 18, 'fontSize' => 11, 'color' => '#6b7280', 'content' => 'СУММА'],
                ['type' => 'text', 'x' => 24, 'y' => 280, 'width' => 352, 'height' => 26, 'fontSize' => 20, 'fontWeight' => 'bold', 'color' => '#16a34a', 'content' => $this->priceLabel($data)],
                ['type' => 'qr', 'x' => 130, 'y' => 320, 'width' => 140, 'height' => 140],
                ['type' => 'text', 'x' => 24, 'y' => 472, 'width' => 352, 'height' => 18, 'fontSize' => 11, 'color' => '#111827', 'content' => 'Билет {{ticket.number}} · Заказ №{{order.id}}'],
                ['type' => 'text', 'x' => 24, 'y' => 496, 'width' => 352, 'height' => 40, 'fontSize' => 10, 'color' => '#9ca3af', 'content' => 'Сохраните билет на устройстве или распечатайте. Предъявите QR на входе.'],
            ],
        ];
    }

    /**
     * Цена в формате «3 500,00 RUB» (русская локаль).
     *
     * @param  array<string, mixed>  $data
     */
    private function priceLabel(array $data): string
    {
        $amount = is_numeric($data['price'] ?? null) ? (float) $data['price'] : 0.0;
        $currency = (string) ($data['currency'] ?? 'RUB');

        return number_format($amount, 2, ',', ' ') . ' ' . $currency;
    }

    /**
     * HTML → PDF через headless-Chrome. Возвращает PDF-байты или null.
     */
    private function htmlToPdf(string $html): ?string
    {
        $chrome = $this->resolveChromeBin();
        if ($chrome === null) {
            Log::info('PDF билета: бинарь Chrome не найден, вложение пропущено.');

            return null;
        }

        $tmp = sys_get_temp_dir();
        $id = uniqid('ticket_', true);
        $htmlPath = $tmp . DIRECTORY_SEPARATOR . $id . '.html';
        $pdfPath = $tmp . DIRECTORY_SEPARATOR . $id . '.pdf';
        $prof = $tmp . DIRECTORY_SEPARATOR . $id . '_prof';

        if (file_put_contents($htmlPath, $html) === false) {
            return null;
        }

        $cmd = sprintf(
            '"%s" --headless --no-sandbox --disable-gpu --no-first-run --no-pdf-header-footer '
            . '--user-data-dir="%s" --print-to-pdf="%s" "file:///%s"',
            $chrome,
            $prof,
            $pdfPath,
            str_replace('\\', '/', $htmlPath)
        );

        $exit = 0;
        $out = [];
        exec($cmd . ' 2>&1', $out, $exit);

        $pdf = is_file($pdfPath) ? file_get_contents($pdfPath) : null;

        foreach ([$htmlPath, $pdfPath] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        if (is_dir($prof)) {
            @array_map('unlink', glob($prof . '/*') ?: []);
            @rmdir($prof);
        }

        if ($pdf === false || $pdf === null || str_starts_with($pdf, '%PDF-') === false) {
            Log::warning('PDF билета: Chrome не вернул PDF.', ['exit' => $exit, 'out' => implode("\n", $out)]);

            return null;
        }

        return $pdf;
    }

    /**
     * Поиск бинаря Chrome в известных местах / через env.
     */
    private function resolveChromeBin(): ?string
    {
        $env = (string) (getenv('TICKET_PDF_CHROME_BIN') ?: '');
        if ($env !== '' && is_file($env)) {
            return $env;
        }

        $candidates = [
            'C:/Program Files/Google/Chrome/Application/chrome.exe',
            'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        $which = @shell_exec('command -v google-chrome chrome chromium chromium-browser 2>/dev/null');
        if (is_string($which) && trim($which) !== '') {
            return strtok(trim($which), " \n");
        }

        return null;
    }
}
