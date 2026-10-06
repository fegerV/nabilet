<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Http\Controllers;

use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Support\StaffRole;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Tickets\Http\Resources\TicketCardResource;
use Nabilet\Modules\Tickets\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Билеты покупателя.
 *
 * Авторизация обязательна (routes/api.php). Разграничение по владельцу:
 * у `tickets` нет колонки `user_id` — владелец определяется через
 * `ticket.order.user_id`. Сотрудник (admin/manager) видит все билеты —
 * ему это нужно для чекина и поддержки; обычный пользователь только свои.
 *
 * Чужой билет — 404, не 403: 403 подтвердил бы, что билет существует
 * (перебор/IDOR, см. `NotFoundError`).
 */
class TicketController extends Controller
{
    /**
     * Билеты текущего покупателя-гостя (витрина «Мои билеты»).
     *
     * Витрина — гостевая: покупатель не имеет аккаунта, заказ создаётся с
     * `user_id = NULL` и принадлежит корзине по гостевому токену (`X-Cart-Token`,
     * контракт D5). Поэтому «мои билеты» — это билеты заказов, чьи корзины несут
     * токен этого браузера. Чужой токен ничего не вернёт (fail-closed: нет
     * токена → пустой список, а не 401, чтобы гость видел пустой экран, а не
     * ошибку авторизации). Маршрут НЕ под auth:api по той же причине.
     */
    public function mine(Request $request): JsonResponse
    {
        $token = CartToken::fromRequest($request);

        // A guest token is a bearer capability for signed QR payloads. Require
        // UUID-sized entropy here even though other authenticated cart paths
        // accept short account-derived tokens; otherwise a guessed short token
        // could disclose another buyer's tickets.
        if ($token === null || strlen($token) < 32) {
            return response()->json(['data' => []]);
        }

        $cartIds = Cart::query()
            ->where('cart_token', $token)
            ->pluck('id');

        if ($cartIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $orderIds = Order::query()
            ->whereIn('cart_id', $cartIds)
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $tickets = Ticket::query()
            ->with(['orderItem', 'session.venue', 'seat.row.sector', 'standingZone', 'event'])
            ->whereIn('order_id', $orderIds)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => TicketCardResource::collection($tickets),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['order_id', 'session_id', 'user_id', 'status']);
        $perPage = max(1, min(100, (int) $request->get('per_page', 20)));

        // Only relations that actually exist on the Ticket model. `session`,
        // `seat` and `inventoryItem` were invented here and threw
        // "Call to undefined relationship" on every list request.
        $query = Ticket::query()->with(['order', 'orderItem', 'scans']);

        if (! StaffRole::isStaff($request->user())) {
            // Принудительно, а не «если не задан»: иначе чужой user_id в query
            // вернул бы чужие билеты.
            $query->whereHas('order', fn ($q) => $q->where('user_id', $request->user()->id));
        } elseif (isset($filters['user_id'])) {
            $query->whereHas('order', fn ($q) => $q->where('user_id', $filters['user_id']));
        }

        if (isset($filters['order_id'])) {
            $query->where('order_id', $filters['order_id']);
        }

        if (isset($filters['session_id'])) {
            $query->where('session_id', $filters['session_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $tickets = $query->paginate($perPage);

        return response()->json([
            'data' => $tickets,
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
                'last_page' => $tickets->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->assertCanSee($request, $ticket);

        $ticket->load(['order', 'orderItem', 'scans']);

        return response()->json(['data' => $ticket]);
    }

    public function qrCode(Request $request, Ticket $ticket): JsonResponse
    {
        // `qr_payload` — подписанный токен входа: кто его получил, тот проходит
        // по билету. Поэтому маршрут закрыт и владельцем, и авторизацией.
        $this->assertCanSee($request, $ticket);

        // The signed QR string lives in `qr_payload` (NB1.<id>.<token>.<sig>,
        // see QrSigner §30). There is no `qr_code` column in the spec schema —
        // reading it returned null silently or crashed with strict attribute
        // access. And there is no named route `tickets.checkin`: the check-in
        // endpoints are POST /tickets/checkin/scan|verify, so route() there
        // threw UrlGenerationException (500) on every call.
        return response()->json([
            'data' => [
                'ticket_id' => $ticket->id,
                'public_id' => $ticket->public_id,
                'qr_payload' => $ticket->qr_payload,
                'qr_version' => $ticket->qr_version,
                'checkin_endpoint' => url('/api/v1/tickets/checkin/verify'),
            ],
        ]);
    }

    /**
     * Scan history for a ticket (route: GET /tickets/{ticket}/history).
     * The method was referenced by the route but never existed — every call
     * was a 500 (BadMethodCallException). §32: at the door, operators need to
     * see when and where a ticket was first scanned.
     */
    public function history(Request $request, Ticket $ticket): JsonResponse
    {
        $this->assertCanSee($request, $ticket);

        $scans = $ticket->scans()
            ->orderBy('scanned_at', 'desc')
            ->get();

        return response()->json([
            'data' => $scans,
            'meta' => [
                'ticket_id' => $ticket->id,
                'status' => $ticket->status,
                'used_at' => $ticket->used_at?->toIso8601String(),
                'revoked_at' => $ticket->revoked_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Fail-closed: билет без заказа или с чужим заказом для не-сотрудника
     * неотличим от несуществующего.
     */
    private function assertCanSee(Request $request, Ticket $ticket): void
    {
        if (StaffRole::isStaff($request->user())) {
            return;
        }

        $ownerId = $ticket->order?->user_id;

        if ($ownerId === null || (int) $ownerId !== (int) $request->user()->id) {
            throw new NotFoundError('Ticket', (string) $ticket->public_id);
        }
    }
}
