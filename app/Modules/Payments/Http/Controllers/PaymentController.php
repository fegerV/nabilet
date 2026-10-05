<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Http\Controllers;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\StaffRole;
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
            // `exists` с `bail`+`integer`: без `bail` MySQL приводит строку к
            // числу (`id = '1abc'` совпадает с 1), и правило пропускает мусор.
            'order_id' => ['bail', 'required', 'integer', 'exists:orders,id'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $order = Order::query()->find((int) $validated['order_id']);

        // Оплатить можно только свой заказ: без этой проверки `order_id` из тела
        // позволял инициировать платёж по чужому заказу и получить ссылку на
        // оплату с его суммой.
        if (! StaffRole::isStaff($request->user())
            && ($order === null || $order->user_id === null || (int) $order->user_id !== (int) $request->user()->id)
        ) {
            throw new NotFoundError('Order', (string) $validated['order_id']);
        }

        $result = $this->paymentService->initiatePayment(
            (int) $validated['order_id'],
            ['idempotency_key' => $validated['idempotency_key'] ?? null],
        );

        return response()->json(['data' => [
            'payment' => $result['payment'],
            'confirmation_url' => $result['confirmation_url'],
        ]], 201);
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
