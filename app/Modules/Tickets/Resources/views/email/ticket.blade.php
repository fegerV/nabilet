@extends('tickets::layouts.email')

@php
    use Nabilet\Modules\Tickets\Domain\TicketPalette;

    /*
     * Оформление. Раньше этот шаблон рисовал фиксированный фиолетовый градиент
     * и не использовал ничего из того, что приносит `generateTicketData()`:
     * назначенный макет мероприятия на письмо не влиял, афиша не показывалась,
     * а ссылки на страницу с билетом не было вовсе. То есть письмо одиночного
     * билета выглядело одинаково у всех и не давало покупателю способа открыть
     * билет с QR.
     *
     * Теперь письмо берёт те же три вещи, что и живой путь
     * (`OrderNotificationData::ticketBlock`): палитру макета, афишу и кнопку.
     * Расхождение между двумя письмами было единственной причиной, по которой
     * «индивидуальный билет» работал в одном канале и не работал в другом.
     *
     * Значения из `template` уже провалидированы в `TicketPalette` (только
     * hex и короткий белый список), поэтому подставлять их в `style` безопасно.
     * Светлый акцент не делает надпись невидимой: цвет текста считает
     * `TicketPalette::contrastText`.
     */
    $template = is_array($ticketData['template'] ?? null) ? $ticketData['template'] : [];
    $accent = (string) ($template['accent_color'] ?? TicketPalette::DEFAULT_ACCENT);
    $accentText = TicketPalette::contrastText($accent);

    $posterUrl = is_string($ticketData['poster_url'] ?? null) ? $ticketData['poster_url'] : '';
    $ticketUrl = (string) ($ticketData['ticket_url'] ?? '');
    // Готовый QR как data-URI — тот же источник, что у живого пути письма.
    $qrDataUri = is_string($ticketData['qr_data_uri'] ?? null) ? $ticketData['qr_data_uri'] : '';
@endphp

@section('content')
<div style="font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">

    {{-- Афиша. URL абсолютный: относительный путь в письме не разрешается —
         почтовый клиент не знает базового адреса. Показываем только когда
         постер реально задан, иначе получается битая картинка на месте
         заголовка. --}}
    @if($posterUrl !== '')
        <img src="{{ $posterUrl }}" alt="{{ $ticketData['event_name'] }}" width="600"
             style="display: block; width: 100%; max-width: 600px; height: auto; border: 0; outline: none;">
    @endif

    <!-- Header with Event Info -->
    <div style="background: {{ $accent }}; padding: 30px; text-align: center; color: {{ $accentText }};">
        <h1 style="margin: 0; font-size: 24px; font-weight: bold;">{{ $ticketData['event_name'] }}</h1>
        <p style="margin: 10px 0 0; font-size: 16px; opacity: 0.9;">{{ $ticketData['event_date'] }}</p>
        @if($ticketData['venue_name'])
            <p style="margin: 5px 0 0; font-size: 14px; opacity: 0.8;">📍 {{ $ticketData['venue_name'] }}</p>
        @endif
    </div>

    <!-- Ticket Body -->
    <div style="padding: 30px;">
        <!-- Customer Info -->
        <div style="margin-bottom: 25px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
            <p style="margin: 0 0 5px; font-size: 12px; color: #6c757d; text-transform: uppercase;">Билет для</p>
            <p style="margin: 0; font-size: 18px; font-weight: bold; color: #212529;">{{ $ticketData['customer_name'] }}</p>
            <p style="margin: 5px 0 0; font-size: 14px; color: #6c757d;">{{ $ticketData['customer_email'] }}</p>
        </div>

        <!-- Seats Info -->
        <div style="margin-bottom: 25px; padding: 15px; background: #e3f2fd; border-radius: 6px; border-left: 4px solid #2196f3;">
            <p style="margin: 0 0 5px; font-size: 12px; color: #1976d2; text-transform: uppercase;">Места</p>
            <p style="margin: 0; font-size: 16px; font-weight: bold; color: #1565c0;">{{ $ticketData['seats'] }}</p>
        </div>

        <!-- Price -->
        <div style="margin-bottom: 25px; text-align: right;">
            <span style="font-size: 14px; color: #6c757d;">Общая стоимость: </span>
            <span style="font-size: 24px; font-weight: bold; color: #28a745;">{{ number_format($ticketData['price'], 2, ',', ' ') }} {{ $ticketData['currency'] }}</span>
        </div>

        <!-- QR Code Section -->
        <div style="text-align: center; margin: 30px 0; padding: 20px; background: #ffffff; border: 2px dashed #dee2e6; border-radius: 6px;">
            <p style="margin: 10px 0 0; font-size: 12px; color: #6c757d;">Код билета для проверки на входе</p>
            {{-- QR рисуется локально и вкладывается data-URI: подписанный токен
                 не уходит ни на чей сторонний сервер. Картинка — дополнение к
                 тексту ниже, а не замена: Gmail вырезает `data:`-URI из `src`,
                 и у таких получателей остаётся читаемый код. --}}
            @if($qrDataUri !== '')
                <img src="{{ $qrDataUri }}" alt="QR билета" width="164" height="164"
                     style="display: block; margin: 12px auto; width: 164px; height: 164px; border: 0; outline: none;">
            @endif
            {{-- Подписанный payload остаётся текстом: это рабочий fallback и для
                 клиента без картинок, и для контролёра на входе, у которого нет
                 доступа к странице покупателя. QR по нему рисует браузер. --}}
            <code style="display: block; margin: 12px auto 0; max-width: 100%; overflow-wrap: anywhere; font-size: 12px; color: #212529;">{{ $ticketData['qr_payload'] }}</code>
            <p style="margin: 8px 0 0; font-size: 10px; color: #adb5bd;">Билет {{ $ticketData['ticket_number'] }} | Заказ №{{ $ticketData['order_id'] }}</p>
        </div>

        {{-- Кнопка ведёт на страницу «Мои билеты» в витрине: QR рисуется в
             браузере покупателя, поэтому подписанный токен не покидает его
             устройство. Пустой `href` не выводим — в письме он ведёт на саму
             страницу и выглядит сломанной кнопкой. --}}
        @if($ticketUrl !== '')
            <div style="text-align: center; margin: 0 0 25px;">
                <a href="{{ $ticketUrl }}" role="button"
                   style="display: inline-block; padding: 14px 28px; background: {{ $accent }}; color: {{ $accentText }}; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: 600;">Открыть билет</a>
            </div>
        @endif

        <!-- Footer Note -->
        <div style="text-align: center; padding-top: 20px; border-top: 1px solid #dee2e6;">
            <p style="margin: 0; font-size: 12px; color: #6c757d;">
                Пожалуйста, сохраните этот билет на вашем устройстве или распечатайте его.
            </p>
            <p style="margin: 10px 0 0; font-size: 11px; color: #adb5bd;">
                При возникновении вопросов обратитесь в службу поддержки.
            </p>
        </div>
    </div>
</div>
@endsection
