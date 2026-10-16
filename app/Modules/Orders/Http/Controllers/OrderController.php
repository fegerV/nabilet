<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Http\Controllers;

use Nabilet\Core\Errors\AppError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Support\StaffRole;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Services\CartCheckoutService;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Notifications\Jobs\SendOrderNotificationJob;
use Nabilet\Modules\Orders\Http\Requests\StoreOrderRequest;
use Nabilet\Modules\Orders\Http\Resources\OrderResource;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Services\RefundService;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
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
        private readonly OrderService $orderService,
        private readonly RefundService $refundService,
        private readonly CartCheckoutService $cartCheckout,
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

        // `data` — СПИСОК заказов, а не объект пагинатора: схема
        // `OrderListResponse` объявляет `data: array<Order>` + `meta`. До правки
        // сюда подставлялся сам пагинатор, и список оказывался на два уровня
        // глубже обещанного (`data.data[]`), а каждый заказ уходил СЫРЫМИ
        // КОЛОНКАМИ таблицы — с внутренними BIGINT и без полей схемы `Order`.
        // Тот же приём, что в `mine()` ниже: одна форма списка на два пути.
        return response()->json([
            'data' => OrderResource::collection($orders->items())->resolve($request),
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

        // Связи грузятся для других нужд (права, уведомления), но в ответ идёт
        // только схема `Order` — `payments`/`tickets`/`user` в неё не входят и
        // наружу не выносятся: `user` — это PII сотрудника, а `payments` клиент
        // читает своим ресурсом через `GET /payments`.
        $order->load(['items', 'payments', 'tickets', 'user']);

        return response()->json(['data' => new OrderResource($order)]);
    }

    /**
     * `listMyOrders` — `GET /api/v1/me/orders`, «мои заказы».
     *
     * ЗАЧЕМ ОТДЕЛЬНЫЙ МЕТОД, А НЕ ССЫЛКА НА `index()`
     *   `index()` ограничивает выборку владельцем только для НЕ-сотрудника: у
     *   admin/manager фильтр `user_id` остаётся тем, что пришло в query. На
     *   `/me/orders` это неверно по смыслу — путь обещает «мои», и сотрудник,
     *   открывший свой кабинет, получил бы все заказы системы. Здесь владелец
     *   подставляется ВСЕГДА и из query не читается вообще, поэтому подставить
     *   чужой `user_id` неоткуда.
     *
     * Остальные фильтры (`status`, диапазон дат) остаются доступными: они
     * уточняют выборку внутри «моих», а не расширяют её.
     */
    public function mine(Request $request): JsonResponse
    {
        $filters = [
            'user_id' => $request->user()->getKey(),
        ] + $request->only(['status', 'date_from', 'date_to']);

        $orders = $this->orderService->paginate($filters, $this->perPage($request));

        // `data` — СПИСОК заказов, а не объект пагинатора. Схема
        // `OrderListResponse` объявляет `data: array<Order>` + `meta`, и это не
        // придирка к форме: `response()->json(['data' => $paginator])` отдаёт
        // `{"data":{"current_page":1,"data":[…]}}` — список заказов оказывается
        // на два уровня глубже, чем обещает контракт, и клиент, читающий
        // `data[0].id`, получает `undefined`.
        return response()->json([
            'data' => OrderResource::collection($orders->items())->resolve($request),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Форма спеки: «создать заказ ИЗ КОРЗИНЫ» (`CreateOrderRequest.cart_id`).
        // Уходит в `CartCheckoutService::checkoutByPublicId()` — то же тело
        // оформления, что и у витринного `POST /cart/checkout`, поэтому две
        // реализации «корзина → заказ» не могут разойтись.
        //
        // ГОСТЕВАЯ ВЕТКА `checkoutAuth` (X-Checkout-Token) здесь НЕ закрыта
        // архитектурой, а middleware: маршрут требует `auth:api`, так что
        // bearer-ветка спеки работает, а гость оформляет через `POST
        // /cart/checkout`. Чтобы открыть и её, нужен middleware «или Bearer, или
        // токен корзины» — записано в §3.25, не построено.
        if (isset($data['cart_id'])) {
            $result = $this->cartCheckout->checkoutByPublicId(
                (string) $data['cart_id'],
                [
                    'customer_name' => $data['customer_name'] ?? $data['holder_name'] ?? null,
                    'customer_email' => $data['customer_email'] ?? null,
                    'customer_phone' => $data['customer_phone'] ?? null,
                    'promo_code' => $data['promo_code'] ?? null,
                    // Списание бонусов — та же ветка, что и промокод: сумма
                    // проходит через `BonusPolicy` и записывается в журнал внутри
                    // транзакции оформления (см. `CartCheckoutService::convert`).
                    // Правило `prohibited_without:cart_id` в `StoreOrderRequest`
                    // гарантирует, что поле не приходит в прямую форму заказа,
                    // где бонусного счёта нет.
                    'bonus_amount' => (int) ($data['bonus_amount'] ?? 0),
                    // Реферальный код ссылки `/r/{code}` (фаза 4): заводит
                    // приглашение по адресу заказа. Награда ждёт оплаты, а не
                    // оформления — см. `CartCheckoutService::convert()`.
                    'referral_code' => $data['referral_code'] ?? null,
                ],
                CartToken::fromRequest($request),
                $request->user()?->getKey(),
                // Вошедший покупатель — для раннего доступа клуба (фаза 3b):
                // гейт продаж на чекауте сдвигает окно для уровня не ниже порога.
                $request->user(),
            );

            // Контракт `createOrder` отвечает `OrderResponse` (`data: Order`), а
            // не сводкой оформления. Раньше сюда подставлялся результат
            // `checkoutByPublicId()` (`{cart_id, order_id, items, …}`) — форму
            // понимает витринный `POST /cart/checkout`, но не спека: в ней нет
            // ни `totals`, ни `items[].event_title`, а `order_id` — это тот же
            // `public_id` под другим именем. Заказ уже создан в транзакции,
            // поэтому читаем его и отдаём его ресурсом.
            $order = Order::query()
                ->with('items')
                ->where('public_id', (string) $result['order_id'])
                ->firstOrFail();

            return response()->json(['data' => new OrderResource($order)], 201);
        }

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
            'data' => new OrderResource($order->fresh()->load('items')),
        ], 201);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        // Роут закрыт `admin`, но проверка владельца всё равно нужна: сотрудник
        // видит все заказы, значит без неё отмена чужого заказа проходит молча.
        $this->assertCanSee($request, $order);

        $order = $this->orderService->cancelOrder($order);

        return response()->json(['data' => new OrderResource($order->load('items'))]);
    }

    /**
     * Возврат денег по заказу (ТЗ §85).
     *
     * Диалог отмены в админке обещал покупателю «если заказ оплачен — будет
     * инициирован возврат», но бэкенд возвращал только места: `RefundService` не
     * вызывался ниоткуда. Здесь обещание выполняется — возврат идёт по каждому
     * успешному платежу заказа (в норме он один; при ретраях разными картами —
     * по каждому), а `RefundLedger` выводит статус заказа из денег.
     *
     * Роут закрыт `admin`: возврат — финансовая операция.
     */
    public function refund(Request $request, string $order): JsonResponse
    {
        // Идентификатор приходит и числом (админка), и строкой-`public_id`
        // (контракт: OrderIdPath — строка). Число резолвим по ключу, всё
        // остальное — по public_id; неизвестное — fail-closed 404.
        $resolved = preg_match('/^\d{1,19}$/', $order) === 1
            ? Order::query()->whereKey((int) $order)->first()
            : Order::query()->where('public_id', $order)->first();

        if ($resolved === null) {
            throw new NotFoundError('Order', $order);
        }

        $order = $resolved;

        $this->assertCanSee($request, $order);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $payments = $order->payments()
            ->where('status', PaymentStateMachine::SUCCEEDED)
            ->orderBy('id')
            ->get();

        if ($payments->isEmpty()) {
            throw new DomainRuleViolation(
                'The order has no succeeded payment to refund.',
                'ORDER_NOT_PAID',
                ['order_id' => $order->id, 'status' => $order->status],
                422,
            );
        }

        $reason = (string) ($validated['reason'] ?? 'Возврат по заказу');

        foreach ($payments as $payment) {
            // `null` = весь остаток платежа: уже возвращённое RefundService
            // вычитает сам, а повторный возврат отвергает (REFUND_EXCEEDS_BALANCE).
            $this->refundService->refund($payment, null, $reason);
        }

        return response()->json([
            'data' => new OrderResource($order->fresh()->load('items')),
        ]);
    }

    /**
     * Повторно отправить письмо с билетом — самопомощь покупателя.
     *
     * ПОЧЕМУ ЭТО ВООБЩЕ НУЖНО
     *   Письмо с билетом уходит на `orders.customer_email`. До этой правки
     *   покупатель, ошибшийся в адресе хотя бы одной буквой, не получал билет
     *   НИКОГДА: письмо уходило в никуда, исправить адрес было нечем, повторно
     *   отправить — тоже. Опечатка в адресе — не гипотетический случай, а самый
     *   частый способ потерять билет при гостевом чекауте.
     *
     * ПОЧЕМУ МАРШРУТ ВНЕ `auth:api`
     *   Витрина гостевая: корзина ключуется `X-Cart-Token`, у заказа
     *   `user_id = NULL`. Под `auth:api` гость получал бы 401 и починить адрес
     *   не смог бы вообще. Право даёт `holdsRequestAccess()` — тот же приём, что
     *   в `PaymentController` для оплаты гостевого заказа.
     *
     * ПОЧЕМУ МОЖНО МЕНЯТЬ АДРЕС
     *   Смена адреса здесь — не «смена владельца», а исправление опечатки:
     *   доступ к заказу уже подтверждён capability-токеном корзины, и письмо
     *   уходит по новому адресу в тот же момент. Аккаунт не затрагивается.
     *
     * ПОЧЕМУ 404, А НЕ 403
     *   Чужой заказ должен быть неотличим от несуществующего, иначе эндпоинт
     *   превращается в оракул существования заказов (IDOR/BOLA).
     */
    public function resendTicket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Заказ адресуется публичным `public_id` (строка), не внутренним
            // BIGINT: витрина берёт его из `orderId` в `GET /my-tickets` и
            // отправляет обратно (§3.26.7 переносим оба на `public_id`).
            'order' => ['bail', 'required', 'string'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ]);

        $order = Order::query()->where('public_id', (string) $validated['order'])->first();

        if ($order === null || ! $this->holdsRequestAccess($request, $order)) {
            throw new NotFoundError('Order', (string) $validated['order']);
        }

        // Билет выпускается по оплате: у неоплаченного заказа отправлять нечего,
        // и повторная отправка не должна выглядеть как успех.
        if ($order->status !== OrderStateMachine::PAID) {
            throw new AppError(
                'The ticket e-mail can only be re-sent for a paid order.',
                'ORDER_NOT_PAID',
                409,
                ['status' => (string) $order->status],
            );
        }

        $email = $validated['email'] ?? null;

        if ($email !== null && strcasecmp($email, (string) $order->customer_email) !== 0) {
            // Присваивание свойству, а не `update()`: адрес здесь — предмет
            // операции, а не произвольный пользовательский ввод.
            $order->customer_email = $email;
            $order->save();
        }

        // Тот же путь, что и при оплате: письмо собирает SendOrderNotificationJob
        // по текущему статусу заказа.
        SendOrderNotificationJob::dispatch((int) $order->getKey(), (string) $order->status);

        return response()->json([
            'data' => [
                'sent' => true,
                'email' => (string) $order->customer_email,
            ],
        ]);
    }

    /**
     * Есть ли у запроса право доступа к заказу помимо сотрудника.
     *
     *   1. владелец — заказ привязан к аккаунту и совпадает с `$request->user()`;
     *   2. гость, предъявивший `X-Cart-Token` той корзины, из которой создан заказ.
     *
     * Токен — capability: UUID выдаётся браузеру покупателя при первом добавлении
     * места и больше нигде не публикуется. Копия `PaymentController` осознанно:
     * это разные модули, а общий хелпер пришлось бы класть в Core, где он стал бы
     * зависимостью ядра от доменных моделей Cart/Order.
     */
    private function holdsRequestAccess(Request $request, Order $order): bool
    {
        if (StaffRole::isStaff($request->user())) {
            return true;
        }

        $user = $request->user();

        if ($user !== null && $order->user_id !== null && (int) $order->user_id === (int) $user->id) {
            return true;
        }

        $token = CartToken::fromRequest($request);

        if ($token === null || $order->cart_id === null) {
            return false;
        }

        return Cart::query()
            ->where('id', $order->cart_id)
            ->where('cart_token', $token)
            ->exists();
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
