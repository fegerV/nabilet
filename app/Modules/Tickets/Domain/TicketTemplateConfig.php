<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain;

use Nabilet\Core\Errors\ValidationError;

/**
 * Версионированный контракт шаблона билета (TicketTemplateConfig v1.0).
 *
 * Единственная точка, где legacy-поля (`width`/`height`/`backgroundColor`/
 * `backgroundGradient` и element.*) превращаются в каноническую структуру.
 * Рендереры (PDF/PNG/email-картинка) и серверный предпросмотр обязаны
 * работать ТОЛЬКО с результатом normalize(), иначе «поправил размер в модели,
 * а билет остался старого размера» становится возможным снова.
 *
 * Каноническая форма:
 *  version: '1.0'
 *  canvas: {width:int, height:int, unit:'px'}
 *  background: {color:string, gradient:?{from,to,direction}}
 *  elements: [{id,type,z,x,y,width,height,r,rotation,opacity,visible,when,...}]
 *
 * Обратная совместимость: если `canvas` отсутствует, размер берётся из
 * полей модели ( template_json.width/height либо collision width/height ),
 * но после normalize() источник размеров ровно один — canvas.
 */
final class TicketTemplateConfig
{
    public const VERSION = '1.0';

    public const MAX_WIDTH = 5000;
    public const MAX_HEIGHT = 5000;
    public const MAX_ELEMENTS = 300;

    /** @var list<string> */
    private const TYPES = ['text', 'rectangle', 'circle', 'line', 'qr', 'barcode', 'image'];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw, ?int $modelWidth = null, ?int $modelHeight = null): array
    {
        $canvasRaw = is_array($raw['canvas'] ?? null) ? $raw['canvas'] : [];
        $width = self::positiveInt($canvasRaw['width'] ?? $raw['width'] ?? $modelWidth, 400);
        $height = self::positiveInt($canvasRaw['height'] ?? $raw['height'] ?? $modelHeight, 600);

        $backgroundRaw = is_array($raw['background'] ?? null) ? $raw['background'] : [];
        $color = $backgroundRaw['color'] ?? $raw['backgroundColor'] ?? '#ffffff';
        $gradientRaw = $backgroundRaw['gradient'] ?? $raw['backgroundGradient'] ?? null;
        $gradient = null;
        if (is_array($gradientRaw) && isset($gradientRaw['from'], $gradientRaw['to'])) {
            $gradient = [
                'from' => (string) $gradientRaw['from'],
                'to' => (string) $gradientRaw['to'],
                'direction' => (string) ($gradientRaw['direction'] ?? 'to bottom'),
            ];
        }

        $elements = [];
        $index = 0;
        foreach ((is_array($raw['elements'] ?? null) ? $raw['elements'] : []) as $element) {
            if (! is_array($element)) {
                continue;
            }
            $elements[] = self::normalizeElement($element, $index);
            $index++;
        }

        return [
            'version' => self::VERSION,
            'canvas' => ['width' => $width, 'height' => $height, 'unit' => 'px'],
            'background' => ['color' => is_string($color) ? $color : '#ffffff', 'gradient' => $gradient],
            'elements' => $elements,
        ];
    }

    /**
     * @param  array<string, mixed>  $el
     * @return array<string, mixed>
     */
    private static function normalizeElement(array $el, int $index): array
    {
        $type = is_string($el['type'] ?? null) ? $el['type'] : 'rectangle';
        $x = self::number($el['x'] ?? 0);
        $y = self::number($el['y'] ?? 0);
        $width = self::number($el['width'] ?? ($type === 'qr' ? ($el['size'] ?? 120) : ($type === 'circle' ? 2 * self::number($el['r'] ?? 50) : 100)));
        $height = self::number($el['height'] ?? ($type === 'qr' ? ($el['size'] ?? 120) : 100));
        $r = self::number($el['r'] ?? max(1.0, $width / 2));

        return [
            'id' => (string) ($el['id'] ?? ('auto-' . $index)),
            'type' => $type,
            'z' => self::int($el['z'] ?? $index, $index),
            'x' => $x,
            'y' => $y,
            // Единый источник: центр круга/конечная точки линии хранятся только здесь,
            // чтобы все рендереры читали одну и ту же геометрию.
            'x1' => self::number($el['x1'] ?? $x),
            'y1' => self::number($el['y1'] ?? $y),
            'x2' => self::number($el['x2'] ?? ($x + $width)),
            'y2' => self::number($el['y2'] ?? ($type === 'line' ? $y : $y + $height)),
            'cx' => self::number($el['cx'] ?? ($x + $r)),
            'cy' => self::number($el['cy'] ?? ($y + $r)),
            'width' => $width,
            'height' => $height,
            'r' => $r,
            'rotation' => self::number($el['rotation'] ?? 0),
            'opacity' => max(0.0, min(1.0, self::number($el['opacity'] ?? 1))),
            'visible' => ($el['visible'] ?? true) !== false,
            'when' => is_array($el['when'] ?? null) ? $el['when'] : null,
            'locked' => ($el['locked'] ?? false) === true,
            // Свойства конкретных типов; рендереры читают их из этого же объекта.
            'content' => is_string($el['content'] ?? null) ? $el['content'] : '',
            'data' => is_string($el['data'] ?? null) ? $el['data'] : '',
            'binding' => is_array($el['binding'] ?? null) ? $el['binding'] : null,
            'fontSize' => self::positiveInt($el['fontSize'] ?? null, 16),
            'fontWeight' => is_string($el['fontWeight'] ?? null) ? $el['fontWeight'] : 'normal',
            'fontFamily' => is_string($el['fontFamily'] ?? null) ? $el['fontFamily'] : '',
            'color' => is_string($el['color'] ?? null) ? $el['color'] : '#111827',
            'fill' => is_string($el['fill'] ?? null) ? $el['fill'] : 'transparent',
            'stroke' => is_string($el['stroke'] ?? null) ? $el['stroke'] : null,
            'strokeWidth' => self::number($el['strokeWidth'] ?? 0),
            'borderRadius' => self::number($el['borderRadius'] ?? 0),
            'size' => self::number($el['size'] ?? max($width, $height)),
            // Изображения ТОЛЬКО по ссылке на медиатеку organization_id;
            // произвольный URL из пользовательского JSON не допускается.
            'media_id' => is_string($el['media_id'] ?? null) ? $el['media_id'] : null,
            // Внешние URL из пользовательского JSON НЕ попадают в канонический
            // конфиг: витрина и рендерер не должны обращаться к чужому серверу.
            'src' => self::trustedImageSource($el['src'] ?? null),
            'alt' => is_string($el['alt'] ?? null) ? $el['alt'] : '',
        ];
    }

    /**
     * Строгая проверка канонического конфигуратора.
     *
     * @param  array<string, mixed>  $config
     * @throws ValidationError  код SCHEMA_VALIDATION_FAILED, fields + objects
     */
    public static function validate(array $config): void
    {
        $errors = [];
        $objects = [];
        $add = static function (string $field, string $message, string $id) use (&$errors, &$objects): void {
            $errors[$field][] = $message;
            $objects[] = ['id' => $id, 'field' => $field];
        };

        if (($config['version'] ?? null) !== self::VERSION) {
            $add('version', 'Unsupported ticket template version.', 'template');
        }
        $canvas = is_array($config['canvas'] ?? null) ? $config['canvas'] : [];
        foreach (['width', 'height'] as $dim) {
            $value = $canvas[$dim] ?? null;
            if (! is_int($value) || $value < 50 || $value > ($dim === 'width' ? self::MAX_WIDTH : self::MAX_HEIGHT)) {
                $add("canvas.$dim", "canvas.$dim must be an integer in [50, " . ($dim === 'width' ? self::MAX_WIDTH : self::MAX_HEIGHT) . '].', 'canvas');
            }
        }
        if (($canvas['unit'] ?? null) !== 'px') {
            $add('canvas.unit', 'Only pixel-based ticket canvases are supported.', 'canvas');
        }

        $elements = is_array($config['elements'] ?? null) ? $config['elements'] : [];
        if (count($elements) > self::MAX_ELEMENTS) {
            $add('elements', 'Too many elements: limit ' . self::MAX_ELEMENTS . '.', 'template');
        }

        $ids = [];
        foreach ($elements as $index => $el) {
            if (! is_array($el)) {
                continue;
            }
            $id = (string) ($el['id'] ?? ('index:' . $index));
            $type = (string) ($el['type'] ?? '');
            if (! in_array($type, self::TYPES, true)) {
                $add("elements.$index.type", 'Unknown element type: ' . $type, $id);
            }
            if (isset($ids[$id])) {
                $add("elements.$index.id", 'Element id must be unique.', $id);
            }
            $ids[$id] = true;

            foreach (['x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'width', 'height', 'r', 'rotation', 'opacity'] as $field) {
                $value = $el[$field] ?? null;
                if (! is_int($value) && ! is_float($value)) {
                    $add("elements.$index.$field", "$field must be a number.", $id);
                    continue;
                }
                if (! is_finite((float) $value) || abs((float) $value) > 1_000_000) {
                    $add("elements.$index.$field", "$field is out of bounds.", $id);
                }
            }
            $opacity = $el['opacity'] ?? null;
            if (is_numeric($opacity) && ((float) $opacity < 0 || (float) $opacity > 1)) {
                $add("elements.$index.opacity", 'opacity must be between 0 and 1.', $id);
            }
            if ($type === 'qr' && self::positiveInt($el['size'] ?? null, 0) < 60) {
                $add("elements.$index.size", 'QR size must be at least 60 px for reliable scanning.', $id);
            }
        }

        if ($errors !== []) {
            throw new ValidationError(
                $errors,
                'Ticket template validation failed',
                ['objects' => $objects],
                'TICKET_TEMPLATE_INVALID',
            );
        }
    }

    private static function positiveInt(mixed $value, int $fallback): int
    {
        return is_int($value) && $value > 0 ? $value : (is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback);
    }

    private static function int(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? (int) $value : $fallback;
    }

    private static function number(mixed $value): float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : 0.0;
    }

    /** Разрешённые источники изображений: только своё хранилище и data URI. */
    private static function trustedImageSource(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (str_starts_with($value, '/storage/') || str_starts_with($value, 'data:image/')) {
            return $value;
        }
        // Абсолютная ссылка на этот же сайт /storage/…
        $prefix = rtrim((string) config('app.url', ''), '/') . '/storage/';
        if ($prefix !== '/storage/' && str_starts_with($value, $prefix)) {
            return '/' . ltrim(substr($value, strlen($prefix)), '/');
        }

        return null;
    }
}
