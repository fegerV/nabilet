<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Http\Controllers;

use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Support\StaffRole;
use Nabilet\Modules\Orders\Http\Requests\StoreOrderRequest;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Заказы.
 *
 * Авторизация обязательна на всех действиях (см. routes/api.php). Здесь —
 * разграничение по владельцу:
 *
 *   - сотрудник (admin/manager) видит все заказы и может фильтровать по
 *     `user_id` / `organization_id`;
 *   - обычный пользователь всегда ограничен своим `user_id`, даже если передал
 *     чужой в query, — иначе фильтр превращается в способ выгрузить чужие заказы;
 *   - чужой заказ для не-сотрудника — 404, не 403: 403 подтвердил бы
 *     существование записи (IDOR/BOLA, см. `NotFoundError`).
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Список фильтров совпадает с тем, что реально понимает репозиторий.
        // Раньше здесь были только organization_id/user_id/status, поэтому
        // `customer_email` и диапазон дат молча игнорировались, а `ilike`
        // внутри репозитория оставался недостижимым (см. OrderRepository).
        $filters = $request->only([
            'organization_id', 'user_id', 'status',
            'customer_email', 'date_from', 'date_to',
        ]);
        $perPage = $this->perPage($request);

        if (! StaffRole::isStaff($request->user())) {
            $filters['user_id'] = $request->user()->id;
        }

        $orders = $this->orderService->paginate($filters, $perPage);

        return response()->json([
            'data' => $orders,
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->assertCanSee($request, $order);

        $order->load(['items', 'payments', 'tickets', 'user']);

        return response()->json(['data' => $order]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        // `user_id` приходит от клиента и раньше записывался как есть — заказ
        // можно было оформить на другого пользователя. Владельца определяет
        // аутентификация; сотруднику разрешено оформить заказ на клиента
        // (телефонный заказ), поэтому его явное значение сохраняется.
        if (! StaffRole::isStaff($request->user())) {
            $data['user_id'] = $request->user()->id;
        } else {
            $data['user_id'] ??= $request->user()->id;
        }

        $order = $this->orderService->createOrder($data);

        return response()->json([
            'data' => $order->fresh(),
        ], 201);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        // Роут закрыт `admin`, но проверка владельца всё равно нужна: сотрудник
        // видит все заказы, значит без неё отмена чужого заказа проходит молча.
        $this->assertCanSee($request, $order);

        $order = $this->orderService->cancelOrder($order);

        return response()->json(['data' => $order]);
    }

    /**
     * Fail-closed: чужой заказ неотличим от несуществующего.
     */
    private function assertCanSee(Request $request, Order $order): void
    {
        if (StaffRole::isStaff($request->user())) {
            return;
        }

        if ($order->user_id === null || (int) $order->user_id !== (int) $request->user()->id) {
            throw new NotFoundError('Order', (string) $order->public_id);
        }
    }

    /** Верхняя граница page size: `?per_page=100000` — это выборка всей таблицы. */
    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->get('per_page', 20)));
    }
}
