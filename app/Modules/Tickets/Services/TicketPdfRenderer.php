<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Nabilet\Core\Support\CssColor;
use Nabilet\Modules\Media\Models\MediaAsset;
use Nabilet\Modules\Tickets\Domain\Barcode\Code128Encoder;
use Nabilet\Modules\Tickets\Domain\Qr\QrEncoder;
use Nabilet\Modules\Tickets\Domain\Qr\QrPngRenderer;
use Nabilet\Modules\Tickets\Domain\TicketTemplateConfig;
use Nabilet\Modules\Tickets\Domain\TicketTemplateDefaults;
use Nabilet\Modules\Tickets\Domain\TicketVariableResolver;
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
        $config = $this->configForTicket($ticket, $data);

        return $this->buildPdf($config, $data, (int) ($ticket->organization_id ?? 0));
    }

    /**
     * Рендер готовой конфигурации холста (для тестов и прямого вызова).
     *
     * @param  array<string, mixed>  $config  ['backgroundColor'=>?,'width'=>?,'height'=>?,'elements'=>[]]
     * @param  array<string, mixed>  $data    вывод TicketGeneratorService::generateTicketData
     */
    public function renderFromConfig(array $config, array $data): ?string
    {
        return $this->buildPdf(
            TicketTemplateConfig::normalize($config),
            $data,
            (int) ($data['organization_id'] ?? 0),
        );
    }

    /**
     * Разрешить шаблон билета и привести его к ЕДИНОМУ контракту.
     *
     * Размер всегда берётся из canvas; поля модели width/height используются
     * только как запасной источник для legacy-шаблонов. Больше никаких
     * «в модели 800×400, а в PDF 400×600».
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function configForTicket(Ticket $ticket, array $data): array
    {
        // Снимок на момент выпуска НЕЗАМЕНИМ: правка/удаление шаблона после
        // продажи не должна менять оформление уже купленного билета.
        $snapshot = $ticket->template_snapshot_json;
        if (is_array($snapshot) && is_array($snapshot['elements'] ?? null)) {
            return TicketTemplateConfig::normalize($snapshot);
        }

        $template = $this->resolver->resolveForTicket($ticket);
        $raw = is_array($template?->template_json) ? $template->template_json : [];

        if (! is_array($raw['elements'] ?? null) || $raw['elements'] === []) {
            return TicketTemplateConfig::normalize($this->defaultConfig($data));
        }

        return TicketTemplateConfig::normalize(
            $raw,
            isset($template->width) ? (int) $template->width : null,
            isset($template->height) ? (int) $template->height : null,
        );
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

        return $this->buildHtml(
            $this->configForTicket($ticket, $data),
            $data,
            (int) ($ticket->organization_id ?? 0),
        );
    }

    /**
     * HTML по произвольной конфигурации холста (для тестов).
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public function renderHtmlFromConfig(array $config, array $data): string
    {
        return $this->buildHtml(
            TicketTemplateConfig::normalize($config),
            $data,
            (int) ($data['organization_id'] ?? 0),
        );
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
        $config = $this->configForTicket($ticket, $data);

        $html = $this->buildHtml($config, $data, (int) ($ticket->organization_id ?? 0));
        if ($html === '') {
            return null;
        }

        return $this->htmlToPng(
            $html,
            (int) ($config['canvas']['width'] ?? 400),
            (int) ($config['canvas']['height'] ?? 600),
        );
    }

    /**
     * PNG по канонической конфигурации (серверный предпросмотр).
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public function renderPngFromConfig(array $config, array $data, int $organizationId = 0): ?string
    {
        $canonical = TicketTemplateConfig::normalize($config);
        $html = $this->buildHtml($canonical, $data, $organizationId);

        return $this->htmlToPng(
            $html,
            (int) ($canonical['canvas']['width'] ?? 400),
            (int) ($canonical['canvas']['height'] ?? 600),
        );
    }

    /**
     * Строит HTML и конвертирует в PDF через headless-Chrome.
     */
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    private function buildPdf(array $config, array $data, int $organizationId): ?string
    {
        $html = $this->buildHtml($config, $data, $organizationId);
        if ($html === '') {
            return null;
        }

        return $this->htmlToPdf($html);
    }

    /**
     * Вёрстка билета из конфигурации холста.
     */
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    private function buildHtml(array $config, array $data, int $organizationId): string
    {
        $canvas = is_array($config['canvas'] ?? null) ? $config['canvas'] : [];
        $width = (int) ($canvas['width'] ?? 400);
        $height = (int) ($canvas['height'] ?? 600);
        $bg = $this->resolveBackground($config);

        $elements = '';
        $ordered = $config['elements'] ?? [];
        usort($ordered, static fn (array $a, array $b): int => ((float) ($a['z'] ?? 0)) <=> ((float) ($b['z'] ?? 0)));
        foreach ($ordered as $el) {
            $elements .= $this->renderElement($el, $data, $organizationId);
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
    /**
     * Один элемент канонического конфигуратора → HTML.
     *
     * Геометрия всех типов теперь читается из одного объекта: x/y — левый
     * верхний угол, width/height — габарит, r — радиус круга, x1/y1/x2/y2 —
     * линия. Rotation/opacity/visibility применяются ОДИНАКОВО ко всем
     * элементам через обёртку, поэтому PDF наконец совпадает с конструктором.
     */
    private function renderElement(mixed $el, array $data, int $organizationId): string
    {
        if (! is_array($el)) {
            return '';
        }
        if (($el['visible'] ?? true) === false) {
            return '';
        }
        if (! $this->visibilityMatches($el['when'] ?? null, $data)) {
            return '';
        }

        $type = is_string($el['type'] ?? null) ? $el['type'] : 'rectangle';
        $x = (float) ($el['x'] ?? 0);
        $y = (float) ($el['y'] ?? 0);

        $inner = match ($type) {
            'text' => $this->textElement($el, $data),
            'qr' => $this->qrElement($el, $data),
            'rectangle' => $this->shapeElement($el, $x, $y, $this->radiusValue($el['borderRadius'] ?? null)),
            // Радиус — единственный источник размера круга (не width/height).
            'circle' => $this->circleElement($el),
            'line' => $this->lineElement($el, $x, $y),
            'image' => $this->imageElement($el, $x, $y, $organizationId),
            'barcode' => $this->barcodeElement($el, $x, $y, $data),
            default => $this->shapeElement($el, $x, $y, '0'),
        };

        return $this->wrapTransform($inner, $el, $x, $y);
    }

    /**
     * Правила условного отображения: variable == value / variable != value / exists.
     * Неизвестные переменные => элемент скрыт (fail-closed).
     *
     * @param  array<string, mixed>|null  $when
     * @param  array<string, mixed>  $data
     */
    private function visibilityMatches(mixed $when, array $data): bool
    {
        if (! is_array($when) || $when === []) {
            return true;
        }
        $variables = TicketVariableResolver::variables($data);
        foreach ($when as $key => $expected) {
            if (! is_string($key)) {
                continue;
            }
            $actual = $variables[$key] ?? '';
            if (is_array($expected)) {
                if (! in_array($actual, array_map('strval', $expected), true)) {
                    return false;
                }
                continue;
            }
            if ((string) $expected === '__exists__' && $actual === '') {
                return false;
            }
            if ((string) $expected !== $actual) {
                return false;
            }
        }

        return true;
    }

    /**
     * Общая обёртка трансформации. Поворот — вокруг центра элемента,
     * прозрачность — 0..1. Оба свойства отсутствуют только в legacy-схемах.
     *
     * @param  array<string, mixed>  $el
     */
    private function wrapTransform(string $inner, array $el, float $x, float $y): string
    {
        $rotation = (float) ($el['rotation'] ?? 0);
        $opacity = max(0.0, min(1.0, (float) ($el['opacity'] ?? 1)));
        $style = '';
        if ($rotation !== 0.0) {
            $cx = $x + (float) ($el['width'] ?? 100) / 2;
            $cy = $y + (float) ($el['height'] ?? 100) / 2;
            $style .= sprintf('transform-origin:%Fpx %Fpx;transform:rotate(%Fdeg);', $cx, $cy, $rotation);
        }
        if ($opacity < 1.0) {
            $style .= sprintf('opacity:%F;', $opacity);
        }

        return $style === ''
            ? $inner
            : sprintf('<div style="%s">%s</div>', $style, $inner);
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
        $align = is_string($el['textAlign'] ?? null) && in_array($el['textAlign'], ['left', 'center', 'right'], true)
            ? 'text-align:' . $el['textAlign'] . ';'
            : '';

        $style = sprintf(
            'position:absolute;left:%1$Fpx;top:%2$Fpx;width:%3$Fpx;min-height:%4$Fpx;'
            . 'font-size:%5$Fpx;font-weight:%6$s;color:%7$s;line-height:1.2;white-space:pre-wrap;'
            . 'word-break:break-word;overflow:hidden;' . $align,
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
        // Единая геометрия линии: x1/y1 → x2/y2. Раньше PDF читал x/y и
        // игнорировал y1, из-за чего линия «Минимализма» уходила из (0,80)
        // в (0,0), а в конструкторе она же считалась через NaN.
        $x1 = (float) ($el['x1'] ?? $x);
        $y1 = (float) ($el['y1'] ?? $y);
        $x2 = (float) ($el['x2'] ?? ($x1 + (float) ($el['width'] ?? 200)));
        $y2 = (float) ($el['y2'] ?? $y1);
        $sw = (float) ($el['strokeWidth'] ?? (($el['stroke'] ?? null) ? 1 : 0));
        $stroke = $this->safeColor($el['stroke'] ?? $el['color'] ?? null, '#111827');
        $minX = min($x1, $x2) - $sw;
        $minY = min($y1, $y2) - $sw;
        $boxW = max(abs($x2 - $x1), $sw * 2, 1) + $sw * 2;
        $boxH = max(abs($y2 - $y1), $sw * 2, 1) + $sw * 2;

        return sprintf(
            '<svg style="position:absolute;left:%Fpx;top:%Fpx;width:%Fpx;height:%Fpx;overflow:visible;" '
            . 'viewBox="%F %F %F %F"><line x1="%F" y1="%F" x2="%F" y2="%F" stroke="%s" '
            . 'stroke-width="%F" /></svg>',
            $minX, $minY, $boxW, $boxH, $minX, $minY, $boxW, $boxH,
            $x1, $y1, $x2, $y2, $stroke, max($sw, 1)
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
     * Круг: радиус r — единственный источник размера. legacy-схемы без r
     * получают радиус из width/2 (см. TicketTemplateConfig::normalize).
     *
     * @param  array<string, mixed>  $el
     */
    private function circleElement(array $el): string
    {
        $r = (float) ($el['r'] ?? 50);
        $x = (float) ($el['x'] ?? 0);
        $y = (float) ($el['y'] ?? 0);
        $size = max(1.0, $r * 2);
        $shape = $this->shapeElement(
            ['width' => $size, 'height' => $size] + $el,
            $x,
            $y,
            '50%',
        );

        return $shape;
    }

    /**
     * Изображение: только доверенный медиаресурс своей организации.
     *
     * Сервер НЕ загружает произвольный URL из template_json (SSRF и утечка
     * покупательских IP). В конфигураторе хранится media_id; здесь мы
     * проверяем принадлежность файла организации билета и вкладываем байты
     * data-URI, чтобы Chrome не делал внешних запросов при печати.
     *
     * @param  array<string, mixed>  $el
     */
    private function imageElement(array $el, float $x, float $y, int $organizationId): string
    {
        $mediaId = (string) ($el['media_id'] ?? '');
        // Снимок билета уже содержит байты изображения: исходный медиаресурс
        // мог быть удалён после продажи, но билет обязан остаться прежним.
        $embedded = (string) ($el['src'] ?? '');
        $uri = str_starts_with($embedded, 'data:image/') ? $embedded : '';
        if ($uri === '' && $mediaId !== '' && $organizationId > 0) {
            $asset = MediaAsset::query()
                ->where('public_id', $mediaId)
                ->where('organization_id', $organizationId)
                ->first();
            if ($asset !== null) {
                $mime = is_string($asset->mime_type) && str_starts_with($asset->mime_type, 'image/')
                    ? $asset->mime_type
                    : 'image/png';
                $disk = is_string($asset->disk) && $asset->disk !== '' ? $asset->disk : 'public';
                $bytes = Storage::disk($disk)->get((string) $asset->path);
                if (is_string($bytes) && strlen($bytes) <= 8_000_000) {
                    $uri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
                }
            }
        }

        if ($uri === '') {
            // Legacy-шаблоны хранили /storage/… вместо media_id. Такой путь
            // принадлежит доверенному хранилищу и читается локально, без HTTP.
            $src = (string) ($el['src'] ?? '');
            if (str_starts_with($src, '/storage/')) {
                $relative = substr($src, strlen('/storage/'));
                $bytes = Storage::disk('public')->get($relative);
                if (is_string($bytes) && strlen($bytes) <= 8_000_000) {
                    $mime = $this->mimeForPath($relative);
                    $uri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
                }
            }
        }

        if ($uri === '') {
            // Fail-closed: не рисуем «картинку» с чужого адреса и не выводим
            // покупателю сломанный <img> с пустым src.
            return $this->placeholderElement($el, $x, $y, 'IMG');
        }

        $w = (float) ($el['width'] ?? 100);
        $h = (float) ($el['height'] ?? 100);
        $alt = htmlspecialchars((string) ($el['alt'] ?? ''), ENT_QUOTES);

        return sprintf(
            '<img src="%s" alt="%s" style="position:absolute;left:%Fpx;top:%Fpx;width:%Fpx;height:%Fpx;display:block;object-fit:contain;">',
            htmlspecialchars($uri, ENT_QUOTES), $alt, $x, $y, $w, $h,
        );
    }

    /**
     * Настоящий CODE128-B, локально. Контролёр НЕ проверяет билет по штрихкоду —
     * это информационный элемент, подписанный QR остаётся единственным пропуском.
     *
     * @param  array<string, mixed>  $el
     */
    /**
     * @param  array<string, mixed>  $data
     */
    private function barcodeElement(array $el, float $x, float $y, array $data): string
    {
        $value = (string) ($el['data'] ?? $el['content'] ?? '');
        if ($value === '' && is_array($el['binding'] ?? null)) {
            $variable = (string) ($el['binding']['variable'] ?? '');
            $variables = TicketVariableResolver::variables($data);
            $value = $variables[$variable] ?? '';
        }
        $value = TicketVariableResolver::render($value, $data);
        if ($value === '') {
            return $this->placeholderElement($el, $x, $y, 'BARCODE');
        }

        $w = (float) ($el['width'] ?? 200);
        $h = (float) ($el['height'] ?? 50);
        $color = $this->safeColor($el['color'] ?? null, '#111827');
        $uri = Code128Encoder::dataUri($value, $w, $h, $color);

        return sprintf(
            '<img src="%s" alt="barcode" style="position:absolute;left:%Fpx;top:%Fpx;width:%Fpx;height:%Fpx;display:block;">',
            htmlspecialchars($uri, ENT_QUOTES), $x, $y, $w, $h,
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
        // Один источник переменных для PDF, PNG, письма и личного кабинета.
        // Неизвестные плейсхолдеры заменяются пустой строкой, а не печатаются
        // покупателю как сырой {{...}}.
        $text = TicketVariableResolver::render($content, $data);

        return htmlspecialchars($text, ENT_QUOTES);
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
     *
     * Правило — у CssColor. Здесь была его четвёртая копия, и она была шире
     * остальных на слово `none` (законное значение SVG `fill`), хотя докблок
     * обещал «как в TicketPalette».
     */
    private function safeColor(mixed $value, string $fallback): string
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        return CssColor::normalizeFill($value) ?? $fallback;
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
        $background = is_array($config['background'] ?? null) ? $config['background'] : [];
        $gradient = $background['gradient'] ?? $config['backgroundGradient'] ?? null;

        if (is_array($gradient) && isset($gradient['from'], $gradient['to'])) {
            $from = $this->safeColor($gradient['from'], '#ffffff');
            $to = $this->safeColor($gradient['to'], '#ffffff');
            $direction = is_string($gradient['direction'] ?? null) && $gradient['direction'] !== ''
                ? $this->safeDirection($gradient['direction'])
                : 'to bottom';

            return "linear-gradient({$direction}, {$from}, {$to})";
        }

        return $this->safeColor($background['color'] ?? $config['backgroundColor'] ?? null, '#ffffff');
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

        $process = new Process([
            $chrome,
            '--headless',
            '--no-sandbox',
            '--disable-gpu',
            '--no-first-run',
            '--disable-dev-shm-usage',
            '--no-pdf-header-footer',
            '--user-data-dir=' . $prof,
            '--print-to-pdf=' . $pdfPath,
            'file:///' . str_replace('\\', '/', $htmlPath),
        ]);
        $process->setTimeout(self::chromeTimeout());

        $pdf = null;
        try {
            // Очистка ВСЕГДА, даже при исключении/тайм-ауте: иначе временные
            // профили Chrome копятся до исчерпания диска.
            $process->run();
            if (is_file($pdfPath)) {
                $bytes = file_get_contents($pdfPath);
                $pdf = $bytes === false ? null : $bytes;
            }
            if ($pdf === null || str_starts_with($pdf, '%PDF-') === false) {
                Log::warning('PDF билета: Chrome не вернул PDF.', [
                    'exit' => $process->getExitCode(),
                    'error' => $process->getErrorOutput(),
                ]);

                return null;
            }

            return $pdf;
        } catch (\Throwable $e) {
            Log::warning('PDF билета: ошибка headless Chrome.', ['message' => $e->getMessage()]);

            return null;
        } finally {
            $this->cleanupFiles([$htmlPath, $pdfPath], $prof);
        }
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

        $process = new Process([
            $chrome,
            '--headless',
            '--no-sandbox',
            '--disable-gpu',
            '--no-first-run',
            '--disable-dev-shm-usage',
            '--hide-scrollbars',
            '--force-device-scale-factor=2',
            '--window-size=' . $width . ',' . $height,
            '--user-data-dir=' . $prof,
            '--screenshot=' . $pngPath,
            'file:///' . str_replace('\\', '/', $htmlPath),
        ]);
        $process->setTimeout(self::chromeTimeout());

        try {
            $process->run();
            $bytes = is_file($pngPath) ? file_get_contents($pngPath) : false;
            if ($bytes === false || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
                Log::warning('PNG билета: Chrome не вернул PNG.', [
                    'exit' => $process->getExitCode(),
                    'error' => $process->getErrorOutput(),
                ]);

                return null;
            }

            return $bytes;
        } catch (\Throwable $e) {
            Log::warning('PNG билета: ошибка headless Chrome.', ['message' => $e->getMessage()]);

            return null;
        } finally {
            $this->cleanupFiles([$htmlPath, $pngPath], $prof);
        }
    }

    /** MIME-тип по расширению локального файла (только изображения). */
    private function mimeForPath(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }

    /** Тайм-аут headless Chrome — защита от зависшего рендера в очереди. */
    private static function chromeTimeout(): float
    {
        return max(5.0, (float) (config('nabilet.tickets.chrome_timeout_seconds', 20)));
    }

    /**
     * Рекурсивная очистка временных файлов и профиля Chrome.
     *
     * Старый cleanup удалял только файлы верхнего уровня и вызывал rmdir —
     * профили с вложенными Default/Crashpad оставались на диске навсегда.
     *
     * @param  list<string>  $files
     */
    private function cleanupFiles(array $files, string $profileDir): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (! is_dir($profileDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($profileDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($profileDir);
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
