<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Services;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Tickets\Domain\TicketPalette;
use Nabilet\Modules\Tickets\Services\TicketGeneratorService;
use Throwable;

/**
 * Данные заказа для подстановки в шаблон письма.
 *
 * Собирает переменные, которые администратор видит в редакторе шаблонов.
 * Значения отдаются скалярами; блок билетов — `Htmlable`, чтобы сервис
 * отправки вставил его в HTML как есть (QR-код — разметка, её экранировать
 * нельзя), а в текстовой версии снял теги.
 */
class OrderNotificationData
{
    public function __construct(
        private readonly TicketGeneratorService $ticketGenerator,
    ) {}

    /**
     * @return array<string, scalar|Htmlable|null>
     */
    public function build(Order $order): array
    {
        $order->loadMissing(['event', 'session.venue', 'user']);

        $variables = [
            'customer_name' => $this->customerName($order),
            'order_number' => (string) $order->order_number,
            'event_name' => (string) ($order->event?->title ?? ''),
            'event_date' => $order->session?->starts_at?->format('d.m.Y H:i') ?? '',
            'venue_name' => (string) ($order->session?->venue?->name ?? ''),
            'total' => $this->money($order),
        ];

        // Билеты прикладываются только к оплаченному заказу. До оплаты их нет
        // физически, а в письмах об отмене и возврате они уже аннулированы —
        // показывать там QR-код значит подсказывать человеку несуществующий
        // билет.
        if ($order->status === OrderStateMachine::PAID) {
            $order->loadMissing('tickets');

            $variables['tickets_html'] = new HtmlString($this->ticketsHtml($order));
            $variables['tickets_text'] = $this->ticketsText($order);
        }

        return $variables;
    }

    private function customerName(Order $order): string
    {
        $name = trim((string) ($order->customer_name ?? ''));

        return $name !== '' ? $name : (string) ($order->user?->name ?? 'Покупатель');
    }

    private function money(Order $order): string
    {
        $amount = number_format(((int) $order->total_amount) / 100, 2, ',', ' ');
        $currency = (string) ($order->currency ?? 'RUB');

        return $amount . ' ' . match ($currency) {
            'RUB' => '₽',
            default => $currency,
        };
    }

    private function ticketsHtml(Order $order): string
    {
        $blocks = [];

        foreach ($order->tickets as $ticket) {
            try {
                $data = $this->ticketGenerator->generateTicketData($ticket);
            } catch (Throwable $e) {
                // Один нечитаемый билет не должен лишать покупателя письма
                // целиком: пропускаем его и пишем в лог.
                Log::warning('Билет не попал в письмо: не удалось подготовить данные.', [
                    'ticket_id' => $ticket->id,
                    'order_id' => $order->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            $blocks[] = $this->ticketBlock($data);
        }

        // Молчаливая потеря билетов — худший исход этого метода: письмо уходит,
        // покупатель считает, что билет внутри, и узнаёт об обратном на входе.
        // Один пропущенный билет — предупреждение, но если не собрался НИ ОДИН
        // при непустом списке, это ошибка уровня `error`: заказ оплачен, а
        // билетов в письме нет ни одного.
        if ($blocks === [] && $order->tickets->isNotEmpty()) {
            Log::error('В письмо не попал ни один билет — покупателю ушло письмо без билетов.', [
                'order_id' => $order->id,
                'tickets_total' => $order->tickets->count(),
            ]);
        }

        return implode('', $blocks);
    }

    /**
     * Один билет как HTML-карточка.
     *
     * ПОЧЕМУ КАРТОЧКА, А НЕ ХОЛСТ КОНСТРУКТОРА
     *
     * Макет билета — холст с абсолютными координатами (400×600 по умолчанию).
     * Почтовые клиенты не поддерживают `position:absolute`, а Outlook
     * выбрасывает его молча, поэтому «отрисовать макет в письме» означало бы
     * обещать вид, которого письмо не держит. В письмо переносится смысловая
     * карточка + палитра макета (см. `TicketPalette`), а точный вид билета
     * покупатель видит на странице по кнопке — там QR рисует браузер, и
     * подписанный payload не покидает его устройство.
     *
     * Вёрстка нарочно таблично-простая: инлайновые стили, `role="button"` у
     * ссылки, никаких flex/grid — их не понимает часть почтовых клиентов.
     */
    private function ticketBlock(array $data): string
    {
        $seat = (string) ($data['seats'] ?? '');
        $number = (string) ($data['ticket_number'] ?? '');
        $qr = (string) ($data['qr_payload'] ?? '');

        $template = is_array($data['template'] ?? null) ? $data['template'] : [];
        $accent = (string) ($template['accent_color'] ?? TicketPalette::DEFAULT_ACCENT);
        $background = (string) ($template['background_color'] ?? TicketPalette::DEFAULT_BACKGROUND);
        $templateName = (string) ($template['name'] ?? '');

        $posterUrl = is_string($data['poster_url'] ?? null) ? $data['poster_url'] : '';
        $ticketUrl = (string) ($data['ticket_url'] ?? '');

        $poster = $posterUrl === ''
            ? ''
            : '<img src="' . e($posterUrl) . '" alt="' . e((string) ($data['event_name'] ?? '')) . '"'
                . ' width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0;outline:none;" />';

        // QR рисуется локально (никакого сервиса картинок) и вкладывается как
        // data-URI, поэтому подписанный payload не покидает письмо.
        //
        // Картинка — ДОПОЛНЕНИЕ, а не замена тексту ниже. Gmail вырезает
        // `data:`-URI из `src`, и у таких получателей не осталось бы ничего,
        // кроме пустого места. Размеры заданы и в атрибутах, и в стилях:
        // Outlook игнорирует часть CSS, но атрибуты понимает.
        $qrDataUri = is_string($data['qr_data_uri'] ?? null) ? $data['qr_data_uri'] : '';

        $qrImage = $qrDataUri === ''
            ? ''
            : '<img src="' . e($qrDataUri) . '" alt="QR билета" width="164" height="164"'
                . ' style="display:block;margin:0 auto 12px;width:164px;height:164px;border:0;outline:none;" />';

        // Название макета — только для диагностики: покупателю имя шаблона
        // ничего не говорит, а администратору по нему видно, какой макет
        // реально применился. Отдаём его в `data-` атрибуте, не в тексте.
        $templateAttr = $templateName === '' ? '' : ' data-template="' . e($templateName) . '"';

        // Кнопка — только если ссылка есть. Пустой `href` в письме ведёт на
        // текущую страницу и выглядит как сломанная кнопка.
        //
        // Цвет надписи не `#ffffff` константой: акцент — фирменный цвет
        // заказчика, и на светлом акценте белый текст не виден (см.
        // `TicketPalette::contrastText`).
        $button = $ticketUrl === ''
            ? ''
            : '<div style="margin:16px 0 0;">'
                . '<a href="' . e($ticketUrl) . '" role="button"'
                . ' style="display:inline-block;padding:12px 24px;background:' . e($accent) . ';'
                . 'color:' . e(TicketPalette::contrastText($accent)) . ';'
                . 'text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;">'
                . 'Открыть билет</a></div>';

        return '<div' . $templateAttr . ' style="border:1px solid #e5e7eb;border-radius:8px;'
            . 'overflow:hidden;margin:0 0 12px;background:' . e($background) . ';">'
            . $poster
            . '<div style="padding:16px;">'
            . '<div style="font-size:12px;color:#6b7280;text-transform:uppercase;">Билет</div>'
            . '<div style="font-size:18px;font-weight:700;margin:2px 0 8px;color:'
            . e(TicketPalette::DEFAULT_TEXT) . ';">' . e($number) . '</div>'
            . '<div style="font-size:14px;margin-bottom:8px;color:' . e(TicketPalette::DEFAULT_TEXT) . ';">'
            . 'Место: <b>' . e($seat) . '</b></div>'
            . $qrImage
            // Подписанный payload остаётся текстом: это рабочий fallback и для
            // клиента без картинок, и для проверяющего на входе, у которого
            // нет доступа к странице покупателя.
            . '<div style="font-size:12px;color:#374151;word-break:break-all;">'
            . 'Код для входа: ' . e($qr) . '</div>'
            . $button
            . '</div>'
            . '</div>';
    }

    private function ticketsText(Order $order): string
    {
        $lines = [];

        foreach ($order->tickets as $ticket) {
            try {
                $data = $this->ticketGenerator->generateTicketData($ticket);
            } catch (Throwable) {
                continue;
            }

            $lines[] = sprintf(
                'Билет %s — место %s, код %s',
                (string) ($data['ticket_number'] ?? ''),
                (string) ($data['seats'] ?? ''),
                (string) ($data['qr_payload'] ?? ''),
            );

            // Ссылка на страницу с QR — и в текстовой версии: часть клиентов
            // показывает только её, и без ссылки такой покупатель остался бы
            // с одной длинной строкой payload.
            $ticketUrl = (string) ($data['ticket_url'] ?? '');

            if ($ticketUrl !== '') {
                $lines[] = 'Открыть билет: ' . $ticketUrl;
            }
        }

        return implode("\n", $lines);
    }
}
