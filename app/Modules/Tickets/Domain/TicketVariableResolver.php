<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain;

use Nabilet\Core\Support\Money;

/**
 * Единственный источник подстановки {{переменных}} билета.
 *
 * Раньше карта переменных жила в трёх местах: в конструкторе (TS), в
 * `TicketPdfRenderer::renderText()` и в email-шаблоне. Любая новая переменная
 * добавлялась в один из трёх списков, и покупатель видел необработанный
 * `{{event.venue}}` там, где дизайнер ожидал адрес. Теперь серверныйResolved
 * список один; фронт получает те же имена из `@/lib/ticketVariables`.
 *
 * Неизвестные переменные удаляются (пустая строка), а не выводятся покупателю
 * сырым шаблонным текстом.
 */
final class TicketVariableResolver
{
    /**
     * @param  array<string, mixed>  $data  TicketGeneratorService::generateTicketData()
     * @return array<string, string>
     */
    public static function variables(array $data): array
    {
        $currency = strtoupper((string) ($data['currency'] ?? 'RUB'));
        $price = $data['price'] ?? null;
        $formattedPrice = $price instanceof Money
            ? $price->format('ru_RU', omitWholeKopecks: true)
            : (string) ($data['price_text'] ?? '');
        $symbol = Money::symbolFor($currency);

        return [
            // Мероприятие
            'event.name' => (string) ($data['event_name'] ?? ''),
            'event.date' => (string) ($data['event_date'] ?? ''),
            'event.time' => (string) ($data['event_time'] ?? ''),
            'event.venue' => (string) ($data['venue_name'] ?? ''),
            'event.address' => (string) ($data['venue_address'] ?? ''),
            'event.organizer' => (string) ($data['organizer_name'] ?? ''),
            'event.poster' => (string) ($data['poster_url'] ?? ''),
            'event.age' => (string) ($data['age_limit'] ?? ''),
            'event.rules' => (string) ($data['entry_rules'] ?? ''),
            'event.timezone' => (string) ($data['timezone'] ?? ''),

            // Площадка
            'venue.name' => (string) ($data['venue_name'] ?? ''),
            'venue.address' => (string) ($data['venue_address'] ?? ''),
            'venue.city' => (string) ($data['venue_city'] ?? ''),
            'organizer.name' => (string) ($data['organizer_name'] ?? ''),
            'organizer.logo' => (string) ($data['organizer_logo'] ?? ''),

            // Билет и заказ
            'ticket.number' => (string) ($data['ticket_number'] ?? ''),
            'ticket.holder' => (string) ($data['customer_name'] ?? ''),
            'ticket.price' => $formattedPrice,
            'ticket.currency' => $symbol,
            'ticket.priceWithCurrency' => trim($formattedPrice . ' ' . $symbol),
            'ticket.barcode' => (string) ($data['barcode_value'] ?? ''),
            'ticket.url' => (string) ($data['ticket_url'] ?? ''),

            'order.id' => (string) ($data['order_id'] ?? ''),
            'order.public_id' => (string) ($data['order_public_id'] ?? ''),
            // Человекочитаемый номер заказа (NB-…), а не внутренний BIGINT.
            'order.number' => (string) ($data['order_number'] ?? $data['order_public_id'] ?? $data['order_id'] ?? ''),

            // Место / зона
            'seat.row' => (string) ($data['seat_row'] ?? ''),
            'seat.number' => (string) ($data['seat_number'] ?? ''),
            'seat.info' => (string) ($data['seats'] ?? ''),
            'seat.sector' => (string) ($data['sector_name'] ?? ''),
            'seat.zone' => (string) ($data['standing_zone'] ?? ''),
        ];
    }

    /**
     * Подставить переменные в текст. Неизвестные → пустая строка.
     *
     * HTML-escaping делает ВЫЗЫВАЮЩИЙ (PDF-рендерер), чтобы один и тот же
     * результат можно было использовать и для текстовых полей письма.
     */
    public static function render(string $content, array $data): string
    {
        $variables = self::variables($data);

        return preg_replace_callback('/\{\{([\w.]+)\}\}/', static function (array $m) use ($variables): string {
            return $variables[$m[1]] ?? '';
        }, $content) ?? $content;
    }
}
