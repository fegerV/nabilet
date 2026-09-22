<?php

namespace App\Modules\Tickets\Services;

use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class TicketGeneratorService
{
    /**
     * Генерация электронного билета
     */
    public function generateTicket($booking, $outputPath = null)
    {
        $event = $booking->event;
        $seats = $booking->seats;
        
        // Данные для билета
        $ticketData = [
            'order_id' => $booking->id,
            'event_name' => $event->name,
            'event_date' => $event->start_at->format('d.m.Y H:i'),
            'venue_name' => $event->venue->name ?? '',
            'seats' => $seats->map(fn($seat) => "Ряд {$seat->row}, Место {$seat->number}")->join(', '),
            'price' => $booking->total_price,
            'customer_name' => $booking->user->name,
            'customer_email' => $booking->user->email,
            'qr_code' => $this->generateQrCode($booking),
            'barcode' => $this->generateBarcode($booking),
            'unique_hash' => $booking->hash,
        ];

        // Генерация PDF (используем view + dompdf)
        $pdf = \PDF::loadView('tickets::email.ticket', compact('ticketData'));
        
        if ($outputPath) {
            $pdf->save($outputPath);
            return $outputPath;
        }

        return $pdf->stream("ticket_{$booking->id}.pdf");
    }

    /**
     * Генерация QR кода
     */
    private function generateQrCode($booking)
    {
        $data = json_encode([
            'order_id' => $booking->id,
            'hash' => $booking->hash,
            'event_id' => $booking->event_id,
        ]);

        $qrCode = QrCode::format('png')
            ->size(300)
            ->errorCorrection('H')
            ->generate($data);

        // Сохраняем во временное хранилище или возвращаем base64
        return 'data:image/png;base64,' . base64_encode($qrCode);
    }

    /**
     * Генерация штрих-кода (опционально)
     */
    private function generateBarcode($booking)
    {
        // Можно использовать библиотеку picqer/php-barcode-generator
        return strtoupper(str_replace('-', '', $booking->hash));
    }

    /**
     * Экспорт схемы зала в JSON
     */
    public function exportHallSchema($hallSchema)
    {
        return response()->json([
            'schema' => $hallSchema->layout_data,
            'metadata' => [
                'name' => $hallSchema->name,
                'venue_id' => $hallSchema->venue_id,
                'created_at' => $hallSchema->created_at,
                'total_seats' => $hallSchema->total_seats,
            ]
        ], 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Импорт схемы зала из JSON
     */
    public function importHallSchema($jsonData, $venueId)
    {
        $data = is_string($jsonData) ? json_decode($jsonData, true) : $jsonData;

        if (!isset($data['layout']) || !is_array($data['layout'])) {
            throw new \InvalidArgumentException('Неверный формат схемы зала');
        }

        $hallSchema = new \App\Modules\Venues\Models\HallSchema();
        $hallSchema->venue_id = $venueId;
        $hallSchema->name = $data['name'] ?? 'Импортированная схема';
        $hallSchema->layout_data = $data['layout'];
        $hallSchema->total_seats = $data['total_seats'] ?? count($data['layout']['seats'] ?? []);
        $hallSchema->save();

        return $hallSchema;
    }
}
