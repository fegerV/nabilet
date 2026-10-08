<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain;

/**
 * Цвета билета, извлечённые из произвольного `template_json`.
 *
 * ПОЧЕМУ ТОЛЬКО ЦВЕТА
 *
 * `template_json` — это холст конструктора: элементы с абсолютными
 * координатами (`x`, `y`, `width`, `height`), рассчитанные на фиксированный
 * размер (по умолчанию 400×600). Письмо так отрисовать нельзя: почтовые
 * клиенты не поддерживают `position:absolute`, а Outlook выбрасывает его
 * молча. Попытка «повторить холст в HTML» дала бы билет, который в Gmail
 * выглядит не так, как в конструкторе, — то есть обещание, которое продукт не
 * держит.
 *
 * Поэтому в письмо переносится только то, что переносится честно: палитра.
 * Раскладка письма остаётся смысловой (карточка билета), а макет продолжает
 * управлять тем, чем он реально может управлять на этом канале — цветом.
 * Серверный рендер холста в PNG/PDF отвергнут на этом этапе (см.
 * `outputs/ticket-designer-delivery-plan.md` §4.4–4.5): он требует нового
 * пакета и шрифтов, а выигрыш — только у тех, кто печатает билет.
 *
 * ПОЧЕМУ ЗНАЧЕНИЯ ПРОВЕРЯЮТСЯ, А НЕ ПРОСТО ЧИТАЮТСЯ
 *
 * `template_json` приходит из админки и остаётся произвольным JSON — это
 * осознанное решение (оно позволяет развивать конструктор без миграций).
 * Но цвет отсюда попадает в HTML-атрибут `style="…"`. Значение вида
 * `#fff" onmouseover="…` разорвало бы атрибут и вставило разметку в письмо
 * покупателя. Поэтому принимаются только hex-цвета и короткий белый список
 * ключевых слов; всё остальное молча заменяется значением по умолчанию.
 * «Молча» здесь правильно: сломанный цвет — не повод не отправить билет.
 */
final class TicketPalette
{
    /** Фон карточки билета, если шаблон не задал свой. */
    public const DEFAULT_BACKGROUND = '#f9fafb';

    /** Акцент (полоса заголовка, кнопка), если шаблон не задал свой. */
    public const DEFAULT_ACCENT = '#0f766e';

    /** Цвет текста: тёмный на светлом фоне. Шаблоном не управляется намеренно. */
    public const DEFAULT_TEXT = '#111827';

    /** Цвет текста поверх тёмного/насыщенного фона (плашка заголовка, кнопка). */
    public const TEXT_ON_DARK = '#ffffff';

    /**
     * Ключевые слова CSS, которые безопасно вставлять в атрибут как есть.
     * Список закрытый: это не «поддержка CSS», а защита от разрыва атрибута.
     */
    private const NAMED_COLORS = [
        'black', 'white', 'transparent', 'inherit',
    ];

    private function __construct(
        public readonly string $background,
        public readonly string $accent,
    ) {}

    /**
     * Палитра по умолчанию — для билета без назначенного макета.
     *
     * Это не «ошибка» и не «пустое оформление»: у мероприятия может не быть
     * шаблона вовсе (колонка `events.ticket_template_id` nullable), и тогда
     * билет обязан выглядеть нормально, а не безлико.
     */
    public static function defaults(): self
    {
        return new self(self::DEFAULT_BACKGROUND, self::DEFAULT_ACCENT);
    }

    /**
     * Цвет текста поверх `$background`, чтобы надпись осталась читаемой.
     *
     * ПОЧЕМУ ЭТО НУЖНО
     *
     * Акцент берётся из макета мероприятия, то есть это фирменный цвет
     * заказчика, а не наш. Светлый акцент (например, жёлтый `#fbbf24`) с белым
     * текстом даёт невидимую надпись на кнопке: кнопка выглядит пустой, и
     * покупатель не понимает, куда нажимать. Ошибка при этом тихая — письмо
     * уходит, вёрстка валидна, `style` не сломан, видно только глазами. Поэтому
     * цвет текста вычисляется, а не задаётся константой.
     *
     * Порог 0.5 по относительной яркости WCAG: он даёт верный ответ для всех
     * hex-цветов и не требует таблиц исключений.
     */
    public static function contrastText(string $background): string
    {
        $luminance = self::relativeLuminance($background);

        // Цвет не разобрали — считаем фон тёмным. Это безопаснее: акцент по
        // умолчанию тёмный (#0f766e), и белый текст на неизвестном фоне
        // ошибается реже, чем тёмный.
        if ($luminance === null) {
            return self::TEXT_ON_DARK;
        }

        return $luminance > 0.5 ? self::DEFAULT_TEXT : self::TEXT_ON_DARK;
    }

    /**
     * Относительная яркость CSS-цвета по WCAG, либо `null` для нераспознанного.
     *
     * Поддерживаются ровно те формы, что пропускает `color()`: hex и короткий
     * белый список ключевых слов. Расширять здесь нечего — значение всё равно
     * пришло из того же валидатора.
     */
    private static function relativeLuminance(string $color): ?float
    {
        $color = strtolower(trim($color));

        if ($color === 'white' || $color === 'transparent' || $color === 'inherit') {
            return 1.0;
        }

        if ($color === 'black') {
            return 0.0;
        }

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $color, $matches) !== 1) {
            return null;
        }

        $hex = $matches[1];

        // #abc → #aabbcc: короткая запись разворачивается дублированием цифр.
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $linear = [];

        foreach ([0, 2, 4] as $offset) {
            $channel = hexdec(substr($hex, $offset, 2)) / 255;
            $linear[] = $channel <= 0.03928
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /**
     * Палитра из `template_json`.
     *
     * Ожидаемые ключи (все необязательные):
     *  - `backgroundColor` — фон холста, задаётся конструктором;
     *  - `accentColor` — если конструктор когда-нибудь заведёт явный акцент.
     *
     * Если явного акцента нет, он выводится из первого залитого
     * `rectangle`/`circle` — так «фирменная» плашка, которую администратор
     * нарисовал на билете, становится акцентом письма. Это приближение, и оно
     * намеренное: точное соответствие холсту всё равно недостижимо.
     *
     * @param  mixed  $templateJson  значение колонки `template_json` (array|null|строка)
     */
    public static function fromTemplateJson(mixed $templateJson): self
    {
        $json = self::normalize($templateJson);

        if ($json === null) {
            return self::defaults();
        }

        $background = self::color(
            $json['backgroundColor'] ?? null,
            self::DEFAULT_BACKGROUND
        );

        $accent = self::color($json['accentColor'] ?? null, null)
            ?? self::accentFromElements($json['elements'] ?? null)
            ?? self::DEFAULT_ACCENT;

        return new self($background, $accent);
    }

    /**
     * `template_json` кастуется моделью в `array`, но в старых записях и в
     * ручных фикстурах там может лежать строка с JSON. Разбирать её дешевле,
     * чем падать: билет должен уйти покупателю в любом случае.
     *
     * @return array<string, mixed>|null
     */
    private static function normalize(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * @param  mixed  $elements
     */
    private static function accentFromElements(mixed $elements): ?string
    {
        if (! is_array($elements)) {
            return null;
        }

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            // Только фигуры: у текста `color` — это цвет букв, а не акцент
            // билета, и делать его цветом кнопки значит перекрасить кнопку в
            // цвет мелкого шрифта.
            $type = is_string($element['type'] ?? null) ? $element['type'] : '';

            if ($type !== 'rectangle' && $type !== 'circle') {
                continue;
            }

            foreach (['fill', 'backgroundColor', 'color', 'stroke'] as $key) {
                $color = self::color($element[$key] ?? null, null);

                if ($color !== null) {
                    return $color;
                }
            }
        }

        return null;
    }

    /**
     * Значение как безопасный CSS-цвет, либо `$fallback`.
     */
    private static function color(mixed $value, ?string $fallback): ?string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $value = strtolower(trim($value));

        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value) === 1) {
            return $value;
        }

        if (in_array($value, self::NAMED_COLORS, true)) {
            return $value;
        }

        return $fallback;
    }
}
