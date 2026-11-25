<?php

declare(strict_types=1);

namespace Nabilet\Modules\Kiosk\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Modules\Cart\Services\CartCheckoutService;
use Nabilet\Modules\Cart\Services\CartItemService;
use Nabilet\Modules\Cart\Services\CartService;
use Nabilet\Modules\Kiosk\Domain\KioskDevice as DevicePolicy;
use Nabilet\Modules\Kiosk\Domain\KioskSale as SalePolicy;
use Nabilet\Modules\Kiosk\Http\Middleware\AuthenticateKiosk;
use Nabilet\Modules\Kiosk\Models\KioskDevice;
use Nabilet\Modules\Kiosk\Models\KioskSale;
use Nabilet\Modules\Kiosk\Services\KioskTokenIssuer;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Sessions\Models\Session;

/**
 * API устройства информационного киоска.
 *
 * ВСЕ запросы идут под `AuthenticateKiosk` — organization ставится из токена,
 * пользователя нет и не предполагается. Секрет подписи QR НЕ доступен киоску:
 * билет подписывается на сервере после webhook'а об оплате.
 *
 * Продажа идёт через те же сервисы, что и web-чекаут (CartService → CartItemService
 * → CartCheckoutService → PaymentService), то есть через тот же
 * `lockForUpdate()+decrement`, что защищает сайт от двойной продажи места.
 */
final class KioskController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CartItemService $items,
        private readonly CartCheckoutService $checkout,
        private readonly PaymentService $payments,
        private readonly KioskTokenIssuer $issuer,
    ) {}

    /** Heartbeat: киоск жив, готов принять конфиг и директиву обновления. */
    public function heartbeat(Request $request): JsonResponse
    {
        $device = AuthenticateKiosk::device($request);
        $this->issuer->touch($device);

        $validated = $request->validate([
            'app_version' => ['sometimes', 'string', 'max:50'],
            'config_version' => ['sometimes', 'string', 'max:50'],
        ]);

        $changes = [];

        if (isset($validated['app_version']) && $validated['app_version'] !== $device->app_version) {
            $changes['app_version'] = $validated['app_version'];
        }

        if (isset($validated['config_version']) && $validated['config_version'] !== $device->config_version) {
            $changes['config_version'] = $validated['config_version'];
        }

        if ($changes !== []) {
            $device->forceFill($changes)->save();
        }

        return response()->json([
            'data' => [
                'status' => $device->status,
                'sales_enabled' => $device->maySell(),
                'server_time' => now()->toIso8601String(),
                'config_version' => $device->config_version,
                'offline_after_minutes' => (int) config('nabilet.kiosks.offline_after_minutes', 5),
                'update' => $this->updateDirective($device),
            ],
        ]);
    }

    /** Конфиг киоска: флаг продаж и директива обновления. */
    public function config(Request $request): JsonResponse
    {
        $device = AuthenticateKiosk::device($request);

        return response()->json([
            'data' => [
                'kiosk_id' => $device->public_id,
                'name' => $device->name,
                'sales_enabled' => $device->maySell(),
                'currency' => 'RUB',
                'config_version' => $device->config_version,
                'update' => $this->updateDirective($device),
            ],
        ]);
    }

    /** Киоск сообщил результат установки обновления. */
    public function updateResult(Request $request): JsonResponse
    {
        $device = AuthenticateKiosk::device($request);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . DevicePolicy::UPDATE_APPLIED . ',' . DevicePolicy::UPDATE_FAILED],
            'version' => ['required', 'string', 'max:50'],
            'error' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if ($device->update_status !== DevicePolicy::UPDATE_PENDING) {
            throw new ConflictError('No update is pending for this kiosk.', 'KIOSK_UPDATE_NOT_PENDING');
        }

        $device->forceFill([
            'update_status' => $validated['status'],
            'update_applied_version' => $validated['version'],
            'update_error' => $validated['error'] ?? null,
            'update_finished_at' => now(),
        ])->save();

        return response()->json(['data' => $this->updateDirective($device)]);
    }

    /**
     * Продажа билета.
     *
     * Идемпотентность: `client_sale_id` (UUID) + UNIQUE (device, client_sale_id).
     * Повтор после обрыва связи возвращает УЖЕ СУЩЕСТВУЮЩУЮ продажу с тем же
     * order_id и платежом — второй билет не создаётся никогда.
     */
    public function sale(Request $request): JsonResponse
    {
        if (! (bool) config('nabilet.kiosks.enabled', true)) {
            throw new ConflictError('Kiosk sales are disabled by configuration.', 'KIOSK_SALES_DISABLED');
        }

        $device = AuthenticateKiosk::device($request);

        if (! $device->maySell()) {
            throw new ConflictError('Sales are disabled for this kiosk.', 'KIOSK_SALES_DISABLED');
        }

        $validated = $request->validate([
            'client_sale_id' => ['required', 'string', 'uuid'],
            // ID сеанса приходит и числом, и public_id — как в Session::findOrFailBySessionId.
            // Без явной проверки скалярности массив ушёл бы в findOrFailBySessionId
            // и упал TypeError'ом (500 вместо честной 422).
            'session_id' => [
                'required',
                'bail',
                static function (string $attribute, mixed $value, callable $fail): void {
                    if (! is_int($value) && ! is_string($value)) {
                        $fail('The ' . $attribute . ' must be an integer id or a public id.');
                    }
                },
            ],
            'inventory_item_id' => ['required', 'integer', 'min:1'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'holder_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $clientSaleId = (string) $validated['client_sale_id'];
        $sessionRaw = $validated['session_id'];

        /*
         * ФАЗА 1 — резервирование продажи под локом строки устройства.
         *
         * Раньше existing-проверка и create были вне транзакции: два
         * параллельных нажатия «Продать» с ОДНИМ client_sale_id оба видели
         * «продажи нет», оба успевали создать заказ и платёж, а второй create
         * падал на UNIQUE — 500 и висящий заказ. Блокировка строки киоска
         * сериализует продажи одного устройства (одна касса — одна очередь),
         * а UNIQUE (device, client_sale_id) остаётся вторым рубежом.
         *
         * Тяжёлая работа (корзина, платёж) идёт ПОСЛЕ коммита: не держим
         * транзакцию во время сетевого вызова провайдера.
         */
        $result = DB::transaction(function () use ($device, $clientSaleId, $sessionRaw, $validated): array {
            /** @var KioskDevice $locked */
            $locked = KioskDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();

            $existing = KioskSale::query()
                ->where('kiosk_device_id', $locked->id)
                ->where('client_sale_id', $clientSaleId)
                ->first();

            if ($existing !== null) {
                return ['sale' => $existing, 'replay' => true];
            }

            // Сеанс чужой организации неотличим от несуществующего.
            $session = Session::findOrFailBySessionId($sessionRaw);

            if ((int) $session->organization_id !== (int) $locked->organization_id) {
                throw new NotFoundError('Session', (string) $sessionRaw);
            }

            // `new + save()` вместо `create()`: PHPStan без Larastan не видит
            // __callStatic у Eloquent-модели.
            $sale = new KioskSale([
                'kiosk_device_id' => $locked->id,
                'client_sale_id' => $clientSaleId,
                'status' => SalePolicy::STATUS_PENDING,
                'customer_email' => $validated['customer_email'] ?? null,
                'holder_name' => $validated['holder_name'] ?? null,
            ]);
            $sale->save();

            return ['sale' => $sale, 'replay' => false, 'session_id' => (string) $session->id];
        });

        if ($result['replay']) {
            return response()->json(['data' => $this->salePayload($result['sale'])], 200);
        }

        /** @var KioskSale $sale */
        $sale = $result['sale'];
        $sessionKey = (string) $result['session_id'];
        $paymentUrl = null;

        // ФАЗА 2 — заказ и платёж. Ошибка фиксируется в самой продаже: клиент
        // увидит её при повторном опросе, а не «тихий» 500 без следа.
        try {
            // Токен корзины — сам client_sale_id, а НЕ общий для устройства
            // cart_token. Один общий токен означал бы, что два параллельных
            // нажатия с РАЗНЫМИ sale_id добавляют места в одну корзину: второй
            // checkout забирал бы заказ первого или падал дублем uq_carts_active.
            // Уникальный токен продажи делает сцену независимой, как у гостя.
            $cartToken = $clientSaleId;
            $this->carts->getOrCreateCart($sessionKey, $cartToken);
            $this->items->addItem(
                sessionId: $sessionKey,
                inventoryItemId: (int) $validated['inventory_item_id'],
                quantity: 1,
                token: $cartToken,
            );

            $checkout = $this->checkout->checkout(
                sessionId: $sessionKey,
                customer: array_filter([
                    'email' => $validated['customer_email'] ?? null,
                ]),
                token: $cartToken,
            );

            $order = Order::query()->where('public_id', $checkout['order_id'])->firstOrFail();
            $idempotencyKey = $this->idempotencyKey((int) $device->id, $clientSaleId);
            $paymentResult = $this->payments->initiatePayment((int) $order->id, [
                'idempotency_key' => $idempotencyKey,
            ]);
            $payment = $paymentResult['payment'];
            $paymentUrl = $payment->payment_url ?? null;

            $sale->forceFill([
                'status' => SalePolicy::STATUS_AWAITING_PAYMENT,
                'order_id' => $order->id,
                'order_public_id' => $order->public_id,
                'payment_id' => $payment->id,
                'amount_minor' => (int) ($checkout['total_amount'] ?? $order->total_amount),
                'currency' => 'RUB',
                'idempotency_key' => $idempotencyKey,
            ])->save();
        } catch (\Throwable $e) {
            $sale->forceFill([
                'status' => SalePolicy::STATUS_FAILED,
                'error_code' => 'KIOSK_SALE_FAILED',
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            throw new ConflictError(
                'The kiosk sale could not be completed.',
                'KIOSK_SALE_FAILED',
                ['client_sale_id' => $clientSaleId],
            );
        }

        return response()->json([
            'data' => $this->salePayload($sale, $paymentUrl),
        ], 201);
    }

    /** Статус продажи для опроса экраном киоска. */
    public function saleStatus(Request $request, string $clientSaleId): JsonResponse
    {
        $device = AuthenticateKiosk::device($request);

        $sale = KioskSale::query()
            ->where('kiosk_device_id', $device->id)
            ->where('client_sale_id', $clientSaleId)
            ->first();

        if ($sale === null) {
            throw new NotFoundError('KioskSale', $clientSaleId);
        }

        return response()->json(['data' => $this->salePayload($sale)]);
    }

    /**
     * Стабильный ключ идемпотентности: device + client_sale_id.
     *
     * Новый ULID на каждый ретрай не защитил бы ничего — платёж создавался бы
     * дважды. Ключ влезает в CHAR(96) и уникален в рамках tenants.
     */
    private function idempotencyKey(int $deviceId, string $clientSaleId): string
    {
        return 'kiosk:' . $deviceId . ':' . $clientSaleId;
    }

    /** @return array<string, mixed>|null */
    private function updateDirective(KioskDevice $device): ?array
    {
        if ($device->update_status !== DevicePolicy::UPDATE_PENDING) {
            return null;
        }

        return [
            'target_version' => $device->target_version,
            'url' => $device->update_url,
            'checksum' => $device->update_checksum,
            'issued_at' => $device->update_issued_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function salePayload(KioskSale $sale, ?string $paymentUrl = null): array
    {
        // Главный сценарий идемпотентности: сеть оборвалась ПОСЛЕ создания
        // платежа, но ДО того, как ответ дошёл до киоска. Повтор с тем же
        // client_sale_id получает 200 — и обязан снова отдать ссылку на оплату.
        // Она восстанавливается из платежа по сохранённому payment_id.
        if ($paymentUrl === null && $sale->payment_id !== null) {
            $paymentUrl = DB::table('payments')
                ->where('id', $sale->payment_id)
                ->value('payment_url');
        }

        return [
            'client_sale_id' => $sale->client_sale_id,
            'public_id' => $sale->public_id,
            'status' => $sale->status,
            'order_public_id' => $sale->order_public_id,
            'amount_minor' => $sale->amount_minor,
            'currency' => $sale->currency,
            // QR отдаётся ТОЛЬКО после того, как webhook подтвердил оплату и
            // TicketService выпустил подписанный билет.
            'qr_payload' => $sale->status === SalePolicy::STATUS_ISSUED ? $sale->qr_payload : null,
            'payment_url' => $paymentUrl,
            'error_code' => $sale->error_code,
            'created_at' => $sale->created_at?->toIso8601String(),
            'paid_at' => $sale->paid_at?->toIso8601String(),
            'issued_at' => $sale->issued_at?->toIso8601String(),
        ];
    }
}
