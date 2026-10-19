<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain;

/**
 * Стандартный макет билета — вертикальный, 400×600.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС, А НЕ КОНСТАНТА В РЕНДЕРЕРЕ
 *
 * Один и тот же макет нужен в трёх местах, и разъехаться они не должны:
 *   1. `TicketPdfRenderer::defaultConfig()` — фолбэк, когда мероприятию не
 *      назначен макет (или назначенный удалили);
 *   2. `DefaultTicketTemplateSeeder` — создаёт запись `ticket_templates`, чтобы
 *      макет был виден и редактируем в конструкторе;
 *   3. `TicketBuilder.vue` — заготовка «Стандартный билет», из которой делают
 *      копию под конкретное мероприятие.
 *
 * Пока макет жил только в рендерере, конструктор показывал пустой холст: чтобы
 * получить «тот самый билет», администратору пришлось бы рисовать его заново.
 * Пока он жил бы только в сидере, удаление записи возвращало бы билет к
 * другому оформлению — то есть «по умолчанию» означало бы два разных билета в
 * зависимости от того, есть ли строка в БД.
 *
 * ПОЧЕМУ КООРДИНАТЫ ЗАШИТЫ, А НЕ СЧИТАЮТСЯ
 *
 * Макет — это картинка фиксированного размера: 400×600 при 96 dpi ≈ 106×159 мм.
 * Любая «адаптивная» раскладка здесь врёт, потому что PDF печатается страницей
 * ровно этого размера (см. `@page` в `TicketPdfRenderer::buildHtml()`).
 *
 * ПРАВИЛА ДИЗАЙНА
 *
 *   - тёмная шапка с названием и датой, светлое тело с реквизитами билета;
 *   - QR — в светлой панели: код читается только на светлом фоне с полями;
 *   - цена вынесена отдельной строкой: у разных мероприятий и разных рядов она
 *     разная (её задают вручную на сеансе), и покупатель ищет её глазами;
 *   - ни одного элемента, который конструктор не умеет рисовать: иначе
 *     «стандартный» билет в редакторе выглядел бы иначе, чем в письме.
 */
final class TicketTemplateDefaults
{
    /** Имя записи в списке шаблонов. По нему же сидер ищет существующую строку. */
    public const NAME = 'Стандартный билет (вертикальный)';

    public const WIDTH = 400;

    public const HEIGHT = 600;

    /** Формат для колонки `ticket_templates.format` (совпадает с пресетом конструктора). */
    public const FORMAT = 'mobile';

    /** Акцент по умолчанию — фирменный бирюзовый NABILET. */
    public const ACCENT = '#0f766e';

    /** Фон шапки: почти чёрный с синим подтоном, чтобы белый текст читался. */
    public const HEADER = '#0f172a';

    /**
     * Макет целиком.
     *
     * @param  string  $accent  акцентный цвет (полосы и цена)
     * @param  string  $header  фон шапки
     * @return array{backgroundColor:string,width:int,height:int,elements:list<array<string,mixed>>}
     */
    public static function config(string $accent = self::ACCENT, string $header = self::HEADER): array
    {
        return [
            'backgroundColor' => '#ffffff',
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'elements' => [
                // Шапка: тёмный блок с названием, датой и площадкой.
                ['type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 400, 'height' => 204, 'fill' => $header],
                ['type' => 'rectangle', 'x' => 0, 'y' => 204, 'width' => 400, 'height' => 6, 'fill' => $accent],
                ['type' => 'text', 'x' => 24, 'y' => 30, 'width' => 352, 'height' => 14, 'fontSize' => 10, 'fontWeight' => 'bold', 'color' => '#94a3b8', 'content' => 'ЭЛЕКТРОННЫЙ БИЛЕТ'],
                ['type' => 'text', 'x' => 24, 'y' => 52, 'width' => 352, 'height' => 84, 'fontSize' => 24, 'fontWeight' => 'bold', 'color' => '#ffffff', 'content' => '{{event.name}}'],
                ['type' => 'text', 'x' => 24, 'y' => 140, 'width' => 352, 'height' => 20, 'fontSize' => 15, 'fontWeight' => 'bold', 'color' => '#5eead4', 'content' => '{{event.date}}'],
                ['type' => 'text', 'x' => 24, 'y' => 166, 'width' => 352, 'height' => 18, 'fontSize' => 12, 'color' => '#cbd5e1', 'content' => '{{event.venue}}'],

                // Реквизиты: гость, место, цена.
                ['type' => 'text', 'x' => 24, 'y' => 236, 'width' => 352, 'height' => 14, 'fontSize' => 10, 'fontWeight' => 'bold', 'color' => '#94a3b8', 'content' => 'ГОСТЬ'],
                ['type' => 'text', 'x' => 24, 'y' => 252, 'width' => 352, 'height' => 22, 'fontSize' => 17, 'fontWeight' => 'bold', 'color' => '#0f172a', 'content' => '{{ticket.holder}}'],
                ['type' => 'text', 'x' => 24, 'y' => 288, 'width' => 352, 'height' => 14, 'fontSize' => 10, 'fontWeight' => 'bold', 'color' => '#94a3b8', 'content' => 'МЕСТО'],
                ['type' => 'text', 'x' => 24, 'y' => 304, 'width' => 352, 'height' => 20, 'fontSize' => 15, 'fontWeight' => 'bold', 'color' => '#0f172a', 'content' => '{{seat.info}}'],
                ['type' => 'text', 'x' => 24, 'y' => 338, 'width' => 352, 'height' => 14, 'fontSize' => 10, 'fontWeight' => 'bold', 'color' => '#94a3b8', 'content' => 'СТОИМОСТЬ'],
                ['type' => 'text', 'x' => 24, 'y' => 354, 'width' => 352, 'height' => 28, 'fontSize' => 20, 'fontWeight' => 'bold', 'color' => $accent, 'content' => '{{ticket.price}} {{ticket.currency}}'],

                // Отрывная часть: линия, панель под QR, подписи.
                ['type' => 'line', 'x' => 24, 'y' => 396, 'x2' => 376, 'y2' => 396, 'stroke' => '#e2e8f0', 'strokeWidth' => 1],
                ['type' => 'rectangle', 'x' => 24, 'y' => 412, 'width' => 352, 'height' => 124, 'fill' => '#f8fafc', 'borderRadius' => 12],
                ['type' => 'qr', 'x' => 148, 'y' => 422, 'size' => 104],
                ['type' => 'text', 'x' => 24, 'y' => 542, 'width' => 352, 'height' => 14, 'fontSize' => 10, 'color' => '#64748b', 'content' => 'Покажите QR-код на входе'],
                ['type' => 'text', 'x' => 24, 'y' => 560, 'width' => 352, 'height' => 14, 'fontSize' => 10, 'color' => '#94a3b8', 'content' => 'Билет №{{ticket.number}} · Заказ №{{order.id}}'],
                ['type' => 'rectangle', 'x' => 0, 'y' => 592, 'width' => 400, 'height' => 8, 'fill' => $accent],
            ],
        ];
    }
}
