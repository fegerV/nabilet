@layout('tickets::layouts.email')

@section('content')
<div style="font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
    
    <!-- Header with Event Info -->
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; color: white;">
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
            <span style="font-size: 24px; font-weight: bold; color: #28a745;">{{ number_format($ticketData['price'], 2) }} ₽</span>
        </div>

        <!-- QR Code Section -->
        <div style="text-align: center; margin: 30px 0; padding: 20px; background: #ffffff; border: 2px dashed #dee2e6; border-radius: 6px;">
            <img src="{{ $ticketData['qr_code'] }}" alt="QR Code" style="max-width: 200px; height: auto;" />
            <p style="margin: 10px 0 0; font-size: 12px; color: #6c757d;">Покажите этот код на входе</p>
            <p style="margin: 5px 0 0; font-size: 10px; color: #adb5bd;">Заказ №{{ $ticketData['order_id'] }} | {{ Str::upper($ticketData['unique_hash']) }}</p>
        </div>

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
