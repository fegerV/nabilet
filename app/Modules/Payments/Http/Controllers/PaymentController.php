<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Http\Controllers;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\StaffRole;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\Services\WebhookAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Платежи.
 *
 * Префикс закрыт `auth:api` (routes/api.php), но одной авторизации мало: без
 * проверки владельца любой залогиненный пользователь видел список всех платежей
 * и любой платёж по id, а `POST /payments` позволял инициировать оплату по
 * чужому `order_id`. Владелец платежа — владелец заказа (`payment.order.user_id`).
 *
 * Сотрудник (admin/manager) видит все платежи; обычный пользователь — только
 * свои, принудительно. Чужой платёж — 404 (см. `NotFoundError`).
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService
    ) {}

    public function index(Request $request): JsonResponse
    {
        // `organization_id` добавлен в список: без него он не доходил до сервиса,
        // а сервис на его отсутствие отвечает пустым результатом — список
        // платежей был пуст всегда.
        $filters = $request->only(['order_id', 'status', 'provider', 'organization_id', 'user_id']);
        $perPage = max(1, min(100, (int) $request->get('per_page', 20)));

        if (! StaffRole::isStaff($request->user())) {
            // Обычный пользователь: скоуп — свои платежи. Принудительно, а не
            // «если не задан», иначе чужой user_id в query вернул бы чужие.
            $filters['user_id'] = $request->user()->id;
        } elseif (empty($filters['organization_id'])) {
            // Сотрудник: скоуп — его организация. Сервис fail-closed (без скоупа
            // отдаёт пустой результат), поэтому организация обязана быть
            // заполнена. Именно её отсутствие и делало список пустым раньше.
            // Показывать платежи ВСЕХ организаций нельзя: это и есть
            // межарендаторская утечка, от которой защищает сервис.
            $organizationId = $request->user()->organizations()->value('organizations.id');

            if ($organizationId === null) {
                throw new ValidationError([
                    'organization_id' => ['organization_id is required for this account.'],
                ]);
            }

            $filters['organization_id'] = $organizationId;
        }

        $payments = $this->paymentService->paginate($filters, $perPage);

        return response()->json([
            'data' => $payments,
            'meta' => [
                'current_page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->assertCanSee($request, $payment);

        $payment->load(['order', 'transactions', 'refunds']);

        return response()->json(['data' => $payment]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Здесь стояло `['bail','required','integer','exists:orders,id']`, и это
            // ломало последний шаг покупки. Внешний идентификатор заказа в этой
            // схеме — ULID `public_id` (так его отдаёт `OrderResource.id`, так
            // объявлен `docs/openapi.yaml#OrderIdPath: {type: string}` и именно его
            // возвращает `POST /cart/checkout` в поле `order_id`). Правило
            // `integer` отбраковало этот ULID раньше, чем дело доходило до поиска:
            // витрина получала 422 VALIDATION_ERROR на СВОЁМ ЖЕ ответе checkout, и
            // цепочка «выбрать место → корзина → оплатить» не проходила никогда.
            // Проверено на живом стенде.
            //
            // Тип не ограничиваем правилом `string`: админка и скрипты шлют
            // `orders.id` числом, и правило отбросило бы их 422-й. Существование
            // заказа проверяет `resolveOrder()`, а не правило `exists`: без
            // `bail`+`integer` MySQL приводит строку к числу (`id = '1abc'`
            // совпадает с 1), и `exists` пропускал бы мусор.
            'order_id' => ['bail', 'required'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $reference = $validated['order_id'];

        // Массив/объект в `order_id` — ошибка клиента, а не 500 на приведении типа.
        if (! is_scalar($reference)) {
            throw new ValidationError([
                'order_id' => ['The order id must be a string or an integer.'],
            ]);
        }

        $order = $this->resolveOrder((string) $reference);

        // Оплатить можно только свой заказ: без этой проверки `order_id` из тела
        // позволял инициировать платёж по чужому заказу и получить ссылку на
        // оплату с его суммой. Чужой/несуществующий заказ — 404, не 403.
        if ($order === null || ! $this->mayPay($request, $order)) {
            throw new NotFoundError('Order', (string) $validated['order_id']);
        }

        $result = $this->paymentService->initiatePayment(
            (int) $order->id,
            ['idempotency_key' => $validated['idempotency_key'] ?? null],
        );

        return response()->json(['data' => [
            'payment' => $result['payment'],
            'confirmation_url' => $result['confirmation_url'],
        ]], 201);
    }

    /**
     * Найти заказ по внешнему идентификатору.
     *
     * Принимает обе формы, которыми заказ адресуют в системе:
     *   1. ULID `public_id` — то, что отдаёт `POST /cart/checkout` (`order_id`) и
     *      `OrderResource.id`; основной внешний идентификатор;
     *   2. числовой `orders.id` — админка и внутренние скрипты.
     *
     * Числовой вариант распознаётся только если строка ЦЕЛИКОМ из цифр: MySQL
     * сравнивает `id` со строкой, приводя её к числу, поэтому `'1abc'` совпал бы
     * с заказом 1 и открыл бы чужой заказ. Здесь — точное совпадение либо ULID.
     */
    private function resolveOrder(string $reference): ?Order
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        if (ctype_digit($reference)) {
            return Order::query()->find((int) $reference);
        }

        return Order::query()->where('public_id', $reference)->first();
    }

    /**
     * Кто вправе оплатить заказ.
     *
     * Витрина — гостевой сценарий: корзина ключуется `X-Cart-Token`, покупатель
     * не логинится, поэтому у заказа `user_id = NULL` (проверено на живом
     * заказе: `CartService::checkout()` его не проставляет). Проверка «заказ мой
     * по user_id» для такого заказа не проходит НИКОГДА, а маршрут стоял под
     * `auth:api` — то есть оплатить гостевой заказ было нельзя ни анонимно
     * (401), ни залогиненным (404). Сквозной сценарий «выбрать место → корзина →
     * оплатить» обрывался на последнем шаге.
     *
     * Право даёт одно из трёх:
     *   1. сотрудник (admin/manager) — видит и оплачивает любой заказ;
     *   2. владелец — заказ привязан к аккаунту и совпадает с `$request->user()`;
     *   3. гость, предъявивший `X-Cart-Token` той корзины, из которой создан заказ.
     *
     * Токен — это capability: UUID выдаётся браузеру покупателя при первом
     * добавлении места и больше нигде не публикуется. Без него чужой заказ
     * по-прежнему 404, поэтому IDOR остаётся закрытым — `order_id` сам по себе
     * не даёт ничего. Токен нормализуется (`CartToken::normalize()`: длина и
     * алфавит), сравнение идёт с `cart_token` в БД, а не с пользовательским вводом.
     *
     * Явная проверка `$user !== null` нужна и технически: прежний код обращался
     * к `$request->user()->id` без неё, и анонимный запрос к заказу с
     * заполненным `user_id` упал бы фаталом «Call to a member function on null».
     */
    private function mayPay(Request $request, Order $order): bool
    {
        $user = $request->user();

        if (StaffRole::isStaff($user)) {
            return true;
        }

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
     * Fail-closed: чужой платёж неотличим от несуществующего.
     */
    private function assertCanSee(Request $request, Payment $payment): void
    {
        if (StaffRole::isStaff($request->user())) {
            return;
        }

        $ownerId = $payment->order?->user_id;

        if ($ownerId === null || (int) $ownerId !== (int) $request->user()->id) {
            throw new NotFoundError('Payment', (string) $payment->public_id);
        }
    }

    /**
     * Симулятор успешной оплаты для локальной разработки.
     *
     * Маршрут открыт (вне `auth:api`) намеренно: он воспроизводит возврат
     * покупателя с внешней платёжной страницы, а тот может быть не залогинен.
     * Защита — сам флаг `nabilet.payment.demo_mode`: по умолчанию он `false`, и
     * тогда метод отвечает 403, ничего не подтверждая. На проде этот флаг
     * включать нельзя — иначе платёж можно подтвердить анонимным запросом.
     */
    public function demoPay(Request $request): JsonResponse
    {
        // Только в demo-режиме: симулятор успешной оплаты.
        if (! (bool) config('nabilet.payment.demo_mode', false)) {
            return response()->json([
                'error' => ['code' => 'DEMO_DISABLED', 'message' => 'Demo mode is off'],
            ], 403);
        }

        $paymentId = (string) $request->get('payment_id', '');

        if ($paymentId === '') {
            return response()->json([
                'error' => ['code' => 'MISSING_PAYMENT_ID', 'message' => 'payment_id is required'],
            ], 422);
        }

        $payment = $this->paymentService->confirmDemoPayment($paymentId);

        return response()->json(['data' => $payment]);
    }

    public function webhook(string $provider, Request $request): JsonResponse
    {
        try {
            // НЕ-проверка здесь = приём подделанных уведомлений. Fail-closed.
            app(WebhookAuthenticator::class)->authenticate($request, $provider);
        } catch (DomainRuleViolation $e) {
            // В тестах APP_DEBUG=true, но рендер через ApiExceptionRenderer всё
            // равно может вернуть null на не-API-путях. Гарантированно отвечаем
            // сами, чтобы status/code дошли до клиента.
            return response()->json([
                'error' => [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                    'details' => $e->context,
                ],
            ], $e->status);
        }

        $result = $this->paymentService->processWebhook($provider, $request->json()->all());

        return response()->json(['data' => $result]);
    }
}
