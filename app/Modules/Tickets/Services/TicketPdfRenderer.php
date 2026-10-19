<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Services;

use Illuminate\Support\Facades\Log;
use Nabilet\Modules\Tickets\Domain\Qr\QrEncoder;
use Nabilet\Modules\Tickets\Domain\Qr\QrPngRenderer;
use Nabilet\Modules\Tickets\Domain\TicketTemplateDefaults;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Models\TicketTemplate;

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
     * Рендер готовой конфигурации холста в PNG (для inline-CID картинки в письме).
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public function renderPng(Ticket $ticket): ?string
    {
        $data = $this->generator->generateTicketData($ticket);

        $template = $this->resolver->resolveForTicket($ticket);
        $config = is_array($template?->template_json) ? $template->template_json : null;

        if (! is_array($config) || ! is_array($config['elements'] ?? null) || $config['elements'] === []) {
            $config = $this->defaultConfig($data);
        }

        $html = $this->buildHtml($config, $data);
        if ($html === '') {
            return null;
        }

        return $this->htmlToPng($html, (int) ($config['width'] ?? 400), (int) ($config['height'] ?? 600));
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
        $bg = $this->resolveBackground($config);

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
  html, body { margin: 0; padding: 0; background: transparent; }
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
            'rectangle' => $this->shapeElement($el, $x, $y, $this->radiusValue($el['borderRadius'] ?? null)),
            'circle' => $this->shapeElement($el, $x, $y, '50%'),
            'line' => $this->lineElement($el, $x, $y),
            'image' => $this->placeholderElement($el, $x, $y, 'IMG'),
            'barcode' => $this->placeholderElement($el, $x, $y, '||  ||  ||'),
            default => $this->shapeElement($el, $x, $y, '0'),
        };
    }

    /**
     * Скругление прямоугольника из конструктора (`borderRadius`).
     *
     * Конструктор рисует `borderRadius || '0px'` — без этого метода PDF выводил
     * скругления нулём, и макет с «мягкими» карточками в письме выглядел
     * угловатым. Значение санитизируется: только число в пикселях, иначе 0 —
     * строка из `template_json` попадает в атрибут стиля, а разрывать его
     * нельзя (та же причина, что у `safeColor`).
     */
    private function radiusValue(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0';
        }

        $radius = max(0.0, min(999.0, (float) $value));

        return $radius > 0 ? $radius . 'px' : '0';
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
        $fontFamily = $this->safeFontFamily($el['fontFamily'] ?? null);
        $content = $this->renderText((string) ($el['content'] ?? ''), $data);

        $style = sprintf(
            'position:absolute;left:%1$Fpx;top:%2$Fpx;width:%3$Fpx;min-height:%4$Fpx;'
            . 'font-size:%5$Fpx;font-weight:%6$s;color:%7$s;line-height:1.2;white-space:pre-wrap;'
            . 'word-break:break-word;overflow:hidden;',
            $el['x'] ?? 0, $el['y'] ?? 0, $w, $h, $fs, $weight, $color
        );
        if ($fontFamily !== '') {
            $style .= 'font-family:' . $fontFamily . ';';
        }

        return sprintf('<div style="%s">%s</div>', $style, $content);
    }

    /**
     * @param  array<string, mixed>  $el
     */
    private function qrElement(array $el, array $data): string
    {
        // Конструктор хранит размер QR в `size` (см. `ensureElementDefaults()`),
        // а не в `width`/`height`. Пока рендер читал только `width`, любой QR из
        // макета печатался дефолтными 140 px и уезжал из своей панели.
        $w = (float) ($el['width'] ?? $el['size'] ?? 140);
        $h = (float) ($el['height'] ?? $el['size'] ?? 140);

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
     *
     * Набор переменных совпадает с тем, что предлагает конструктор
     * (`flattenVariables()` в `@/lib/ticketBuilder`): разойдись они — дизайнер
     * поставил бы на макет переменную, которая в PDF осталась бы сырым
     * `{{...}}`, и увидел бы это только покупатель.
     *
     * `ticket.price` отдаётся уже отформатированным («3 500»), а не числом:
     * разделитель разрядов и запятая копеек — часть оформления билета, и
     * конструктор не должен собирать их вручную из двух переменных.
     */
    private function renderText(string $content, array $data): string
    {
        $price = is_numeric($data['price'] ?? null) ? (float) $data['price'] : 0.0;
        $currency = strtoupper((string) ($data['currency'] ?? 'RUB'));

        $flat = [
            'event.name' => (string) ($data['event_name'] ?? ''),
            'event.date' => (string) ($data['event_date'] ?? ''),
            'event.time' => (string) ($data['event_time'] ?? ''),
            'event.venue' => (string) ($data['venue_name'] ?? ''),
            'ticket.number' => (string) ($data['ticket_number'] ?? ''),
            'ticket.holder' => (string) ($data['customer_name'] ?? ''),
            // «3 500» — без копеек, если они нулевые: на билете они не нужны, а
            // «3 500,00» занимает место и читается хуже.
            'ticket.price' => fmod($price, 1.0) === 0.0
                ? number_format($price, 0, ',', ' ')
                : number_format($price, 2, ',', ' '),
            'ticket.currency' => $currency === 'RUB' ? '₽' : $currency,
            'seat.row' => (string) ($data['seat_row'] ?? ''),
            'seat.number' => (string) ($data['seat_number'] ?? ''),
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
     * Фон холста: сплошной цвет либо линейный градиент (текстура из конструктора).
     *
     * Если в макете задан `backgroundGradient` (`{from,to,direction}`), собираем
     * CSS `linear-gradient(...)`. Иначе — сплошной `backgroundColor`. Оба значения
     * проходят через `safeColor`, поэтому инъекции через атрибут стиля нет.
     */
    private function resolveBackground(array $config): string
    {
        $gradient = $config['backgroundGradient'] ?? null;

        if (is_array($gradient) && isset($gradient['from'], $gradient['to'])) {
            $from = $this->safeColor($gradient['from'], '#ffffff');
            $to = $this->safeColor($gradient['to'], '#ffffff');
            $direction = is_string($gradient['direction'] ?? null) && $gradient['direction'] !== ''
                ? $this->safeDirection($gradient['direction'])
                : 'to bottom';

            return "linear-gradient({$direction}, {$from}, {$to})";
        }

        return $this->safeColor($config['backgroundColor'] ?? null, '#ffffff');
    }

    /**
     * Направление градиента: только `to <сторона>` либо угол в градусах.
     * Любое другое значение (потенциальная инъекция) сводится к умолчанию.
     */
    private function safeDirection(string $value): string
    {
        $v = trim($value);

        if (preg_match('/^\d{1,3}deg$/', $v) === 1) {
            return $v;
        }

        if (preg_match('/^to\s+(top|bottom|left|right)(?:\s+(top|bottom|left|right))?$/', $v) === 1) {
            return $v;
        }

        return 'to bottom';
    }

    /**
     * Шрифт текстового элемента: только заранее известный веб-безопасный набор.
     * Произвольная строка из конструктора не проходит и возвращает '' (шрифт
     * наследуется от #ticket), что исключает разрыв атрибута style.
     *
     * @param  mixed  $value
     */
    private function safeFontFamily(mixed $value): string
    {
        static $allowed = [
            'Arial, Helvetica, sans-serif',
            'Georgia, "Times New Roman", serif',
            '"Times New Roman", Georgia, serif',
            'Verdana, Geneva, sans-serif',
            '"Courier New", Courier, monospace',
            'Tahoma, Geneva, sans-serif',
            'Trebuchet MS, sans-serif',
        ];

        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * Макет по умолчанию (мероприятию не назначен шаблон, либо назначенный
     * удалили). Один и тот же дизайн с сидером и заготовкой конструктора —
     * `TicketTemplateDefaults`: иначе «билет по умолчанию» выглядел бы по-разному
     * в зависимости от того, есть ли строка в `ticket_templates`.
     *
     * Акцент берём из палитры мероприятия: если шаблон был и его удалили,
     * билет сохраняет фирменный цвет, а не становится чужим.
     */
    private function defaultConfig(array $data): array
    {
        $accent = $this->safeColor($data['template']['accent_color'] ?? null, TicketTemplateDefaults::ACCENT);

        return TicketTemplateDefaults::config($accent);
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
     * HTML → PNG через headless-Chrome (`--screenshot`). Используется для
     * inline-CID картинки билета в письме: тело письма не умеет `position:absolute`,
     * поэтому готовый растр билета встраивается прямо в письмо и виден сразу.
     *
     * Окно браузера задаётся ровно размером холста, а device-scale-factor=2 даёт
     * чёткий растр. Возвращает PNG-байты или null (Chrome недоступен / не вернул PNG).
     */
    private function htmlToPng(string $html, int $width, int $height): ?string
    {
        $chrome = $this->resolveChromeBin();
        if ($chrome === null) {
            Log::info('PNG билета: бинарь Chrome не найден, inline-картинка пропущена.');

            return null;
        }

        $tmp = sys_get_temp_dir();
        $id = uniqid('ticket_', true);
        $htmlPath = $tmp . DIRECTORY_SEPARATOR . $id . '.html';
        $pngPath = $tmp . DIRECTORY_SEPARATOR . $id . '.png';
        $prof = $tmp . DIRECTORY_SEPARATOR . $id . '_prof';

        if (file_put_contents($htmlPath, $html) === false) {
            return null;
        }

        $cmd = sprintf(
            '"%s" --headless --no-sandbox --disable-gpu --no-first-run --hide-scrollbars '
            . '--force-device-scale-factor=2 --window-size=%d,%d --user-data-dir="%s" '
            . '--screenshot="%s" "file:///%s"',
            $chrome,
            $width,
            $height,
            $prof,
            str_replace('\\', '/', $pngPath),
            str_replace('\\', '/', $htmlPath)
        );

        $exit = 0;
        $out = [];
        exec($cmd . ' 2>&1', $out, $exit);

        $png = is_file($pngPath) ? file_get_contents($pngPath) : null;

        foreach ([$htmlPath, $pngPath] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        if (is_dir($prof)) {
            @array_map('unlink', glob($prof . '/*') ?: []);
            @rmdir($prof);
        }

        if ($png === false || $png === null || str_starts_with($png, "\x89PNG\r\n\x1a\n") === false) {
            Log::warning('PNG билета: Chrome не вернул PNG.', ['exit' => $exit, 'out' => implode("\n", $out)]);

            return null;
        }

        return $png;
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
