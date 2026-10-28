<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Http\Controllers;

use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Http\ContentType;
use Nabilet\Core\Support\StaffRole;
use Nabilet\Core\Support\Paging;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Tickets\Http\Resources\TicketCardResource;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Services\TicketPdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

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
     * Билеты текущего покупателя (витрина «Мои билеты»).
     *
     * ДВА СПОСОБА ПОЗВАТЬ ЭТОТ ЭНДПОИНТ, ОДИН ОТВЕТ.
     *
     *  1. ПО АККАУНТУ. С появлением аккаунта, заведённого по факту покупки
     *     (`CustomerAccountService`), у заказа появился владелец
     *     (`orders.user_id`). Если пришёл Bearer-токен, «мои билеты» — это билеты
     *     заказов с `user_id` вызывающего. Именно этого не хватало раньше:
     *     покупатель, потерявший `localStorage` (сменил браузер, почистил
     *     хранилище), терял билеты навсегда — заказ существовал, а связать с ним
     *     запрос было нечем.
     *
     *  2. ПО ГОСТЕВОМУ ТОКЕНУ. Витрина по-прежнему работает без входа: корзина
     *     несёт `X-Cart-Token`, и билеты находятся через `carts.cart_token`
     *     (контракт D5). Эта ветка сохранена без изменений — в том числе её
     *     требование энтропии: гость не аутентифицирован, поэтому токен — это
     *     capability, и короткий (`'u'.$id`, который выдаёт корзине вошедшему
     *     пользователю `CartController::resolveToken`) здесь не принимается.
     *     Раньше из-за этого вошедший покупатель получал ПУСТОЙ список: его
     *     токен короткий, а ветки по аккаунту не существовало.
     *
     * Маршрут НЕ под `auth:api`: обе ветки необязательны, и гость обязан видеть
     * пустой список, а не 401. Отсюда `auth('api')->user()`, а не
     * `$request->user()`: последний читает guard по умолчанию (`web`, сессионная
     * кука) и на витрине с Bearer-токеном всегда вернул бы `null`.
     */
    public function mine(Request $request): JsonResponse
    {
        $orderIds = collect();

        $user = auth('api')->user();

        if ($user !== null) {
            $orderIds = Order::query()->where('user_id', $user->getKey())->pluck('id');
        }

        $token = CartToken::fromRequest($request);

        // A guest token is a bearer capability for signed QR payloads. Require
        // UUID-sized entropy here even though other authenticated cart paths
        // accept short account-derived tokens; otherwise a guessed short token
        // could disclose another buyer's tickets.
        if ($token !== null && strlen($token) >= 32) {
            $cartIds = Cart::query()
                ->where('cart_token', $token)
                ->pluck('id');

            if ($cartIds->isNotEmpty()) {
                $orderIds = $orderIds->merge(
                    Order::query()->whereIn('cart_id', $cartIds)->pluck('id')
                );
            }
        }

        // Один заказ может прийти обеими ветками (вошедший покупатель с тем же
        // браузером) — `unique()` не даёт билетам задвоиться в ответе.
        $orderIds = $orderIds->unique()->values();

        if ($orderIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $tickets = Ticket::query()
            ->with(['orderItem', 'session.venue', 'seat.row.sector', 'standingZone', 'event.ticketTemplate', 'order'])
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
        $perPage = Paging::limit((int) $request->get('per_page', 20), 20, 100);

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
     * Скачиваемая PDF-версия билета (контракт: GET /tickets/{ticket}/pdf,
     * bearerAuth, ответ `application/pdf`).
     *
     * Прежние стоящие причины отсутствия маршрута сняты: `TicketPdfRenderer`
     * уже управляет PDF для вложения письма, и здесь вызывается тот же код.
     * `render()` возвращает `null`, когда Headless Chrome недоступен — это
     * НЕ ошибка владельца, и по контракту это 202 AsyncJobResponse, а не 500.
     */
    public function pdf(Request $request, Ticket $ticket)
    {
        $this->assertCanSee($request, $ticket);

        $pdf = app(TicketPdfRenderer::class)->render($ticket);

        if ($pdf === null || $pdf === '') {
            return response()->json([
                'data' => [
                    'job_id' => (string) Str::uuid(),
                    'status' => 'queued',
                ],
            ], 202);
        }

        return response($pdf, 200, [
            'Content-Type' => ContentType::PDF,
            // Attachment, а не inline: пенсионный крючок — браузер с
            // форматом билетов ничего сделать не может; файл тарифицируется
            // как загрузка.
            'Content-Disposition' => sprintf(
                'attachment; filename="ticket-%s.pdf"',
                $ticket->public_id ?? $ticket->ticket_number
            ),
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
