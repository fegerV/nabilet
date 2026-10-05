<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Services;

use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Repositories\TicketRepository;
use Nabilet\Modules\Tickets\Domain\TicketIssuance;
use Nabilet\Modules\Tickets\StateMachines\TicketStateMachine;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Core\Support\QrSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TicketService
{
    // NB: the old constructor injected `CheckinEvaluator` — a PURE domain class
    // whose real contract is evaluate(ScanRequest, ?TicketSnapshot). Nothing in
    // this service ever called it correctly (it passed a Ticket model and got a
    // TypeError), and because Laravel's container cannot build that dependency
    // graph cleanly through this service, `app(TicketService::class)` from
    // PaymentService crashed and NO tickets were issued after payment. Check-in
    // decisions live in TicketScanService, which is the only place allowed to
    // talk to the evaluator.
    public function __construct(
        protected TicketRepository $repository,
        protected TicketIssuance $issuance
    ) {}

    /**
     * A6: выпуск билетов для оплаченного заказа.
     *
     * Идемпотентность: повторный вызов (например, ретрай вебхука) НЕ перевыпускает
     * билеты — уже существующие возвращаются как есть. Выпуск разрешён только для
     * статуса 'paid' — терминального успеха машины состояний Order ('completed' в
     * машине отсутствует).
     */
    public function issueTicketsForOrder(Order $order): array
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== 'paid') {
                throw new \RuntimeException('Can only issue tickets for paid orders');
            }

            // Идемпотентность: если билеты уже выпущены — ничего не создаём заново.
            $existing = Ticket::where('order_id', $order->id)->orderBy('id')->get();
            if ($existing->isNotEmpty()) {
                return $existing->all();
            }

            $signer = $this->makeQrSigner();
            $tickets = [];
            $seq = 0;

            foreach ($order->items as $item) {
                $inventory = InventoryItem::find($item->inventory_item_id);

                // `tickets.event_id` и `tickets.session_id` — NOT NULL, поэтому оба
                // обязаны быть определены ДО вставки.
                //
                // Здесь стоял фолбэк `$inventory?->event_id`, и он был мёртвым
                // кодом: колонки `event_id` у `inventory_items` не существует
                // (есть `session_id`, `type`, `seat_id`, `standing_zone_id`,
                // `price_amount`, `capacity`, ...). Поэтому для заказа без
                // `orders.event_id` — например созданного через `POST /orders`
                // или тестовой фикстурой — выпуск падал с
                // `1048 Column 'event_id' cannot be null`, откатывая всю
                // транзакцию вебхука ВМЕСТЕ с подтверждением оплаты: провайдер
                // деньги списал, а заказ оставался неоплаченным.
                //
                // Событие выводим из сеанса — это единственный корректный путь,
                // потому что сеанс всегда принадлежит событию.
                $sessionId = $order->session_id ?? $inventory?->session_id;
                $eventId = $order->event_id
                    ?? ($sessionId !== null ? Session::query()->whereKey($sessionId)->value('event_id') : null);

                if ($sessionId === null || $eventId === null) {
                    // Заказ вне сеанса: билет не к чему привязать. Ронять
                    // транзакцию нельзя — оплата уже подтверждена провайдером,
                    // и 500 откатил бы её. Говорим громко и пропускаем позицию.
                    Log::warning('TicketService: no session/event for order item — ticket skipped', [
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'inventory_item_id' => $item->inventory_item_id,
                    ]);

                    continue;
                }

                for ($i = 0; $i < max(1, (int) $item->quantity); $i++) {
                    $seq++;
                    $publicId = (string) Str::ulid();

                    // QrSigner (§30): в QR нет ни e-mail, ни цены — только
                    // NB1.<ticketPublicId>.<token>.<signature>.
                    $signed = $signer->issue($publicId);

                    $snapshot = $item->seat_snapshot_json ?? [];

                    $ticket = Ticket::create([
                        'public_id' => $publicId,
                        'ticket_number' => sprintf('TCK-%s-%03d', strtoupper(substr($order->public_id, 0, 8)), $seq),
                        'ticket_index' => $i + 1,
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'event_id' => $eventId,
                        'session_id' => $sessionId,
                        'inventory_item_id' => $item->inventory_item_id,
                        'seat_id' => $snapshot['seat_id'] ?? $inventory?->seat_id,
                        'holder_name' => $order->customer_name ?? $order->customer_email,
                        'status' => 'issued',
                        'qr_version' => 1,
                        'qr_token_hash' => hash('sha256', $signed['token']),
                        'qr_payload' => $signed['payload'],
                        'issued_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $tickets[] = $ticket;
                }
            }

            return $tickets;
        });
    }

    /**
     * Секрет подписи QR: TICKET_QR_SECRET / NABILET_QR_SECRET, либо APP_KEY.
     * QrSigner сам отказывается работать с секретом короче 32 байт.
     */
    protected function makeQrSigner(): QrSigner
    {
        $secret = config('nabilet.ticket.qr_secret')
            ?: env('NABILET_QR_SECRET')
            ?: (string) config('app.key');

        // APP_KEY может быть закодирован как base64:...
        if (str_starts_with($secret, 'base64:')) {
            $secret = (string) base64_decode(substr($secret, 7), true);
        }

        return new QrSigner($secret);
    }

    public function findTicket(int $ticketId, ?int $organizationId = null): ?Ticket
    {
        return $this->repository->find($ticketId, $organizationId);
    }

    public function findByPublicId(string $publicId, ?int $organizationId = null): ?Ticket
    {
        return $this->repository->findByPublicId($publicId, $organizationId);
    }

    /**
     * Check-in through the service's own entry point. The decision belongs to
     * the pure CheckinEvaluator (evaluate(ScanRequest, ?TicketSnapshot)) and is
     * owned by TicketScanService; this method delegates so that callers of
     * TicketService keep working without re-implementing the door rules here.
     */
    public function checkInTicket(Ticket $ticket, int $checkinDeviceId, ?string $location = null): array
    {
        return app(\Nabilet\Modules\Tickets\Services\TicketScanService::class)
            ->scan((int) $ticket->id, (int) $ticket->session_id, $checkinDeviceId);
    }

    /**
     * Revoke a ticket (§44). Spec statuses: issued|used|cancelled|refunded|
     * expired|revoked — 'invalidated' does not exist in ck_tickets_status, and
     * the old code wrote non-existent columns invalidated_at/
     * invalidation_reason (SQLSTATE 42S22 on every call).
     */
    public function revokeTicket(Ticket $ticket, string $reason): Ticket
    {
        if ($ticket->status === TicketStateMachine::REVOKED) {
            throw new \RuntimeException('Ticket is already revoked');
        }

        // issued -> revoked and used -> revoked are the only legal paths into
        // `revoked` per TicketStateMachine; terminal states stay terminal.
        $allowed = TicketStateMachine::make()->can($ticket->status, TicketStateMachine::REVOKED);
        if ($allowed === false) {
            throw new \RuntimeException(sprintf(
                'Cannot revoke a ticket in status "%s"',
                (string) $ticket->status
            ));
        }

        return $this->repository->revoke($ticket, $reason);
    }

    /** Back-compatible alias; prefer revokeTicket(). */
    public function invalidateTicket(Ticket $ticket, string $reason): Ticket
    {
        return $this->revokeTicket($ticket, $reason);
    }

    public function getTicketsByOrder(int $orderId): array
    {
        return $this->repository->findByOrder($orderId)->toArray();
    }

    public function getTicketsBySession(int $sessionId, int $limit = 50)
    {
        return $this->repository->findBySession($sessionId, $limit);
    }

    public function getCheckinStats(int $sessionId): array
    {
        return $this->repository->getCheckinStats($sessionId);
    }

    protected function generateBarcodeData(Order $order, $item): string
    {
        // Generate unique barcode data
        return sprintf(
            'ORD-%s-ITEM-%d',
            $order->public_id,
            $item->id
        );
    }

    protected function generateQrCode(Order $order, $item): string
    {
        // Generate QR code data with HMAC-SHA256 signature for security
        // This prevents ticket forgery - the signature must match to be valid
        $ticketData = [
            'order_id' => $order->public_id,
            'item_id' => $item->id,
            'timestamp' => time(),
        ];

        // Get the secret key for signing (use APP_KEY or a dedicated QR_SECRET)
        $secret = config('app.key') ?? config('tickets.qr_secret');
        
        if (empty($secret)) {
            throw new \RuntimeException('QR code signing key not configured. Set APP_KEY or tickets.qr_secret');
        }

        // Create the payload JSON
        $payload = json_encode($ticketData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode ticket data for QR code');
        }

        // Generate HMAC-SHA256 signature
        $signature = hash_hmac('sha256', $payload, $secret);

        // Return signed payload: base64(payload.signature)
        // This format is compact and can be easily decoded by the checker app
        $signedData = base64_encode($payload . '.' . $signature);

        return $signedData;
    }

    /**
     * Verify QR code signature to prevent forgery.
     * 
     * @param string $qrCodeData The QR code data to verify
     * @return array{valid: bool, data?: array, reason?: string}
     */
    public function verifyQrCodeSignature(string $qrCodeData): array
    {
        try {
            // Decode the base64 data
            $decoded = base64_decode($qrCodeData, true);
            
            if ($decoded === false) {
                return ['valid' => false, 'reason' => 'INVALID_BASE64'];
            }

            // Split payload and signature
            $parts = explode('.', $decoded);
            
            if (count($parts) !== 2) {
                return ['valid' => false, 'reason' => 'INVALID_FORMAT'];
            }

            [$payload, $providedSignature] = $parts;

            // Get the secret key
            $secret = config('app.key') ?? config('tickets.qr_secret');
            
            if (empty($secret)) {
                return ['valid' => false, 'reason' => 'SIGNING_KEY_NOT_CONFIGURED'];
            }

            // Verify the signature
            $expectedSignature = hash_hmac('sha256', $payload, $secret);
            
            if (!hash_equals($expectedSignature, $providedSignature)) {
                return ['valid' => false, 'reason' => 'SIGNATURE_MISMATCH'];
            }

            // Decode the payload
            $data = json_decode($payload, true);
            
            if ($data === null) {
                return ['valid' => false, 'reason' => 'INVALID_PAYLOAD_JSON'];
            }

            return ['valid' => true, 'data' => $data];
        } catch (\Exception $e) {
            return ['valid' => false, 'reason' => 'VERIFICATION_ERROR'];
        }
    }
}
