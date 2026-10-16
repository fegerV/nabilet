<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Core\Users\Services\CustomerAccountService;
use Nabilet\Modules\Events\Models\Session;
use Nabilet\Modules\Loyalty\Services\BonusService;
use Nabilet\Modules\Loyalty\Services\LoyaltySettingsService;
use Nabilet\Modules\Loyalty\Services\ReferralService;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Models\OrderItem;
use Nabilet\Modules\Orders\Models\PromoCode;
use Nabilet\Modules\Pricing\Domain\OrderTotals;
use Nabilet\Modules\Pricing\Services\PromoCodeService;
use Nabilet\Modules\Sessions\Models\Session as SessionsSession;

/**
 * CartCheckoutService — превращение корзины в заказ.
 *
 * Выделено из `CartService` (P2). Причина выделения не только в размере: это
 * единственная операция, которая пересекает границу модулей (Cart → Orders →
 * Sessions) и создаёт денежный документ. Всё остальное в корзине —
 * подготовительные операции, которые можно откатить, ничего не потеряв.
 *
 * ЗАВИСИМОСТЬ ОТ ЛОЯЛЬНОСТИ (фаза 2b)
 *   Здесь же появилась третья точка пересечения границы — `Loyalty`: оформление
 *   спрашивает у бонусного счёта, сколько бонусов можно списать, и записывает
 *   списание. Разделить это на «чекаут решает сам» и «лояльность потом
 *   поправит» нельзя: `total_amount` — денежный документ, и он обязан быть
 *   посчитан один раз, вместе со списанием. Поэтому `BonusService` внедрён сюда
 *   напрямую (как и `PromoCodeService`), а не вызывается событием после факта.
 *
 * РЕФЕРАЛЬНАЯ АТРИБУЦИЯ (фаза 4) — ТОЖЕ ЗДЕСЬ, НО БЕЗ ДЕНЕГ
 *   `ReferralService::attachToOrder()` заводит приглашение по адресу заказа.
 *   В отличие от бонусов, это не денежный документ: награда ждёт оплаты
 *   (`OrderReferralObserver`), и расходиться с чекаутом ей нечем. Причина
 *   размещения та же — единственное место, где известен и адрес друга, и
 *   аккаунт, заведённый на чекауте.
 */
final class CartCheckoutService
{
    public function __construct(
        private readonly CartItemService $items,
        private readonly PromoCodeService $promos = new PromoCodeService(),
        private readonly CustomerAccountService $customers = new CustomerAccountService(),
        private readonly BonusService $bonus = new BonusService(),
        // Ранний доступ клуба (фаза 3b): гейт продаж на чекауте сдвигает окно
        // для клубного покупателя. Значение по умолчанию — как у соседей выше.
        private readonly LoyaltySettingsService $loyaltySettings = new LoyaltySettingsService(),
        // Реферальная программа (фаза 4): код из тела оформления связывает
        // заказ с пригласившим. Значение по умолчанию — как у соседей выше.
        private readonly ReferralService $referrals = new ReferralService(),
    ) {
    }

    /**
     * Оформить корзину — создать заказ и его позиции.
     *
     * @param \Nabilet\Modules\Core\Users\Models\User|null $buyer вошедший покупатель (ранний доступ клуба, фаза 3b)
     * @return array результат оформления с данными заказа
     * @throws ConflictError          корзина истекла или инвентарь исчерпан
     * @throws DomainRuleViolation    корзина пуста или продажи закрыты
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException корзина не найдена
     */
    public function checkout(string $sessionId, array $customer = [], ?string $token = null, ?User $buyer = null): array
    {
        // D5: оформить можно только корзину того же покупателя.
        $token = $this->requireToken($token);

        $expired = false;

        // Флаг «корзина истекла» поднимается ИЗ транзакции наружу, а не бросается
        // внутри неё. Это исправление конкретного дефекта, а не стилистика:
        // `throw` внутри `DB::transaction` откатывает транзакцию целиком — вместе
        // с только что выполненным возвратом инвентаря и пометкой `abandoned`.
        // Раньше здесь стоял `throw`, и очистка не сохранялась НИКОГДА: корзина
        // оставалась `active`, места — удержанными, а комментарий «освобождает
        // инвентарь немедленно» описывал недостижимую ветку. Воспроизведено
        // тестом CartWritePathTest::test_expired_cart_returns_every_seat_it_reserved.
        $result = DB::transaction(function () use ($sessionId, $token, $customer, $buyer, &$expired): ?array {
            $cart = Cart::query()
                ->where('session_id', $sessionId)
                ->where('status', 'active')
                ->where('cart_token', $token)
                ->with(['items.inventoryItem'])
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                // D-8/S8: 404 на повторный checkout после успешного оформления
                // волновал покупателя больше, чем информировал. Если корзина
                // конвертирована этим же токеном и у неё есть заказ — честно
                // возвращаем Тот же заказ (идемпотентный replay с 'replayed':
                // true), вместо инфраструктурного NOT_FOUND. Случай «корзина
                // вообще не наша» остаётся 404 по домену (NotFoundError).
                $replayed = $this->replayConvertedOrder($sessionId, $token);

                if ($replayed !== null) {
                    return $replayed;
                }

                throw new NotFoundError('Cart', $sessionId);
            }

            return $this->convert($cart, $customer, $expired, null, $buyer);
        });

        // Возврат инвентаря уже зафиксирован транзакцией — только теперь можно
        // отказать, не потеряв очистку.
        if ($expired) {
            throw ConflictError::cartExpired();
        }

        return $result ?? [];
    }

    /**
     * Оформить корзину по её `public_id` — форма спеки (`POST /api/v1/orders`,
     * `CreateOrderRequest.cart_id`).
     *
     * ЗАЧЕМ ВТОРОЙ ВХОД. Спека адресует корзину из пути (`/carts/{cart}`) и
     * создаёт заказ из `cart_id`; приложение адресовало её парой
     * (`session_id` + `X-Cart-Token`). Оба входа ведут в ОДНУ и ту же
     * `convert()` — расходиться здесь значило бы получить две реализации
     * оформления заказа.
     *
     * ПРАВО НА КОРЗИНУ. Владелец — тот же, что и у CRUD-путей: гостевой токен
     * из `X-Cart-Token` совпадает с `carts.cart_token`, либо Bearer-пользователь
     * владеет корзиной (`carts.user_id`). Чужая корзина — 404, а не 403: существование
     * или несуществование чужого `public_id` — не информация для спрашивающего.
     *
     * @param \Nabilet\Modules\Core\Users\Models\User|null $buyer вошедший покупатель (ранний доступ клуба, фаза 3b)
     * @throws ConflictError          корзина истекла или инвентарь исчерпан
     * @throws DomainRuleViolation    корзина пуста или продажи закрыты
     */
    public function checkoutByPublicId(string $publicId, array $customer = [], ?string $token = null, ?int $userId = null, ?User $buyer = null): array
    {
        $expired = false;

        $result = DB::transaction(function () use ($publicId, $customer, $token, $userId, $buyer, &$expired): ?array {
            $cart = $this->lockOwnedByPublicId($publicId, $token, $userId);

            return $this->convert($cart, $customer, $expired, $userId, $buyer);
        });

        if ($expired) {
            throw ConflictError::cartExpired();
        }

        return $result ?? [];
    }

    /**
     * Токен покупателя обязателен: без него корзина не находится, а не
     * «находится любая». Сообщение то же, что и раньше, — контракт 422.
     */
    private function requireToken(?string $token): string
    {
        $token = trim((string) $token);

        if ($token === '') {
            throw new ValidationError(['cart_token' => ['The cart token is required.']], 'Cart token is required.');
        }

        return $token;
    }

    /**
     * Корзина по `public_id`, принадлежащая спрашивающему, — под блокировкой.
     *
     * 404 и на «нет такой», и на «не твоя»: различать их — значит подтверждать
     * существование чужих корзин по их идентификатору.
     */
    private function lockOwnedByPublicId(string $publicId, ?string $token, ?int $userId): Cart
    {
        $token = trim((string) $token);

        return Cart::query()
            ->where('public_id', $publicId)
            ->where('status', 'active')
            ->where(function ($query) use ($token, $userId): void {
                if ($userId !== null) {
                    $query->where('user_id', $userId);
                }

                if ($token !== '') {
                    $query->orWhere('cart_token', $token);
                }
            })
            ->with(['items.inventoryItem'])
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Кто покупатель для раннего доступа (фаза 3b).
     *
     * Три источника, от самого авторитетного:
     *   1. Явно переданный покупатель — контроллер отдал `$request->user()`.
     *   2. `$userId` (второй вход, спека `POST /orders`) — грузим пользователя.
     *   3. Владелец корзины (`carts.user_id`) — корзина уже принадлежала
     *      покупателю до оформления.
     * Все NULL — гость: ранний доступ не положен (0 часов).
     */
    private function earlyAccessBuyer(Cart $cart, ?int $userId, ?User $buyer): ?User
    {
        if ($buyer !== null) {
            return $buyer;
        }

        if ($userId !== null) {
            return User::query()->find($userId);
        }

        return $cart->user;
    }

    /**
     * Кто владелец создаваемого заказа.
     *
     * Три источника, в порядке убывания авторитетности:
     *
     *   1. Явно переданный `$userId` — вошедший покупатель или сотрудник,
     *      оформляющий заказ (второй вход, `checkoutByPublicId`).
     *   2. Уже проставленный владелец корзины (`carts.user_id`) — покупатель,
     *      вошедший ДО наполнения корзины.
     *   3. Аккаунт, выведенный из адреса заказа (`CustomerAccountService`) —
     *      гостевая витрина. Аккаунт создаётся здесь, если адреса ещё нет.
     *
     * ПОБОЧНЫЙ ЭФФЕКТ, КОТОРЫЙ И ЕСТЬ СМЫСЛ. Найденная личность записывается
     * в `carts.user_id`. `PromoCodeService::buildContext()` читает покупателя
     * ИМЕННО оттуда, а не из аргумента, — без этой записи промокод всё равно
     * оценился бы как гостевой, и `per_user_limit` (ТЗ §86) не сработал бы.
     * Запись идёт в той же транзакции, что и заказ: откат оформления
     * откатывает и владельца.
     *
     * `null` возвращается только когда адрес заказа пуст или синтаксически
     * неверен — тогда заказ остаётся гостевым, ровно как и раньше.
     */
    private function resolveBuyerId(Cart $cart, array $customer, ?int $userId): ?int
    {
        $buyerId = $userId ?? ($cart->user_id === null ? null : (int) $cart->user_id);

        if ($buyerId === null) {
            $buyerId = $this->customers->resolveForCheckout(
                (string) ($customer['customer_email'] ?? ''),
                $customer['customer_name'] ?? null,
                $customer['customer_phone'] ?? null,
            );
        }

        if ($buyerId !== null && (int) $cart->user_id !== $buyerId) {
            $cart->update(['user_id' => $buyerId]);
        }

        return $buyerId;
    }

    /**
     * Общее тело оформления: всё, что происходит ПОСЛЕ того, как корзина
     * найдена и заблокирована.
     *
     * Вынесено из `checkout()`, когда у оформления появился второй вход (по
     * `public_id`): два публичных метода, одно тело. Расхождение двух тел — это
     * два разных поведения «создать заказ», то есть баг, который находят в
     * проде.
     */
    private function convert(Cart $cart, array $customer, bool &$expired, ?int $userId = null, ?User $buyer = null): ?array
    {
        // Check cart expiration
            if ($cart->expires_at < CarbonImmutable::now()) {
                $cart->update(['status' => 'abandoned']);

                // A13: истёкшая корзина освобождает инвентарь немедленно —
                // иначе места зависают в held/sold до работы sweeper'а.
                $this->items->releaseCartInventory($cart);

                $expired = true;

                return null;
            }

            // Check cart has items
            if ($cart->items->isEmpty()) {
                throw new DomainRuleViolation('Cart is empty.', 'CART_EMPTY');
            }

            // Sales gate at checkout: seats may still sit in a cart that was filled
            // while sales were live, but the order itself can only be placed while
            // the session is `on_sale` and inside its sales window. Existing holds
            // are kept (the sweeper releases them on TTL), so the buyer can retry
            // if the organizer reopens sales before the hold expires.
            //
            // Фаза 3b: клубный покупатель проверяется тем же сдвигом окна, что и
            // на создании корзины — иначе «ранний доступ» открывался бы только на
            // корзину, а оплатить место раньше времени было нельзя.
            $session = Session::findOrFailBySessionId((string) $cart->session_id);

            $earlyAccessHours = $this->loyaltySettings->earlyAccessHoursForUser(
                $this->earlyAccessBuyer($cart, $userId, $buyer)
            );

            if (! $session->isSellableAt(null, $earlyAccessHours)) {
                throw DomainRuleViolation::salesClosed(
                    (string) ($session->public_id ?? $session->id),
                    ['reason' => $session->salesBlockReason(null, $earlyAccessHours)]
                );
            }

            // Validate all items still have available inventory.
            // addItem() already reserved (decremented) available_quantity for
            // these cart items — the seat belongs to THIS cart now. A concurrent
            // buyer physically cannot take it (atomic decrement). So we only
            // fail if the quantity somehow went negative or the item vanished.
            foreach ($cart->items as $item) {
                $inventoryItem = $item->inventoryItem;

                if ($inventoryItem === null) {
                    throw ConflictError::seatUnavailable((string) $item->inventory_item_id);
                }

                if ($inventoryItem->available_quantity < 0) {
                    throw ConflictError::seatUnavailable((string) $inventoryItem->id, [
                        'requested_quantity' => $item->quantity,
                        'available_quantity' => $inventoryItem->available_quantity,
                    ]);
                }
            }

            // Mark cart as converted
            $cart->update(['status' => 'converted']);

            // A6 (план b): до оплаты места НЕ помечаются 'sold'. sold — это
            // подтверждённая продажа (InventoryItemStateMachine: held → sold на
            // подтверждении платежа). Промежуточное состояние — available_quantity
            // уже удержан холдами; статус остаётся held/sold_out. Это снимает
            // дефект «места сгорают при неоплате»: неоплаченные места никогда не
            // зависают в sold, а освобождаются sweeper'ом / checkout-expiry.
            foreach ($cart->items as $item) {
                if ($item->inventoryItem !== null && $item->inventoryItem->status === 'available') {
                    $item->inventoryItem->update(['status' => 'held']);
                }
            }

            // Холды корзины переживают checkout: converted_at ставится только на
            // payment.succeeded (markHoldsAsConverted). Если платёж так и не
            // прошёл, заказ заберёт orders:expire-sweeper и вернёт места.

            // Create the order: checkout succeeded, seats are sold, cart is
            // converted — persist the sale so it appears in the admin orders list.
            $session = $cart->session()->first();
            $event = $session?->event()->first();
            $organizationId = $event->organization_id ?? $session?->venue?->organization_id ?? 1;

            // Аккаунт покупателя: заказ получает владельца, а промокод —
            // личность, по которой считается лимит.
            //
            // Раньше владельцем был только аутентифицированный пользователь, а
            // у гостевого заказа `user_id` оставался NULL. Следствие не
            // косметическое: `PromoCodeService::buildContext()` берёт личность из
            // `carts.user_id`, а `userRedemptions()` при `null` возвращает 0 —
            // значит `per_user_limit` у гостя не действовал ВООБЩЕ, а условие
            // `first_purchase` проходило бесконечно.
            //
            // ПОРЯДОК ВАЖЕН: до оценки промокода, а не после. Проставленная
            // позже личность не попадёт в контекст оценки, и код снова будет
            // оценён как гостевой.
            $buyerId = $this->resolveBuyerId($cart, $customer, $userId);

            // Промокод: код проверяется ДО создания заказа — чистая оценка через
            // тот же PromoEvaluator, что и POST /promo-codes/validate (второго
            // источника правды о скидке нет). Отказ бросает исключение внутри
            // DB::transaction: заказ не создаётся, пометка корзины `converted`
            // откатывается, инвентарь остаётся удержанным холдом — покупатель
            // может исправить код и повторить оформление.
            $promoCode = null;
            $promoEvaluation = null;

            if (!empty($customer['promo_code'])) {
                $promoCode = $this->promos->findByCode(
                    $organizationId,
                    (string) $customer['promo_code']
                );

                if ($promoCode === null) {
                    throw new DomainRuleViolation(
                        'Promo code not found.',
                        'PROMO_CODE_NOT_FOUND',
                    );
                }

                $promoEvaluation = $this->promos->evaluateForCart($promoCode, $cart);

                if (!$promoEvaluation->valid) {
                    throw new DomainRuleViolation(
                        sprintf('Promo code cannot be applied: %s.', (string) $promoEvaluation->rejectionReason),
                        'PROMO_CODE_REJECTED',
                    );
                }
            }

            $subtotalAmount = (int) ($cart->total_amount ?? 0);
            $discountAmount = $promoEvaluation?->discount->minorUnits() ?? 0;
            // Двойная страховка от отрицательного итога: evaluator уже режет
            // скидку по подытогу применимых строк, но scope-строки могут быть
            // меньше всей корзины, а total_amount когда-нибудь начнёт включать
            // сборы — тогда subtotal и total разойдутся.
            $discountAmount = min($discountAmount, $subtotalAmount);

            // Бонусы. Покупатель называет сумму, лимит считает `BonusPolicy`:
            // 30% от подытога, не больше остатка после скидки и не больше
            // баланса. Решение о лимите живёт в модуле лояльности — здесь его
            // не пересчитывают, иначе «30%» существовало бы в двух местах.
            $currency = (string) ($cart->currency ?? 'RUB');
            $requestedBonus = (int) ($customer['bonus_amount'] ?? 0);
            $bonusCap = $this->bonus->spendableFor(
                $buyerId,
                Money::of($subtotalAmount, $currency),
                Money::of($discountAmount, $currency),
            );

            // Отказ, а не тихое урезание. Молчаливое урезание изменило бы цену,
            // которую покупатель подтвердил: он просил списать 600, а заплатил
            // бы как за 300. Отказ возвращает его к выбору с актуальным лимитом.
            if ($requestedBonus > $bonusCap->minorUnits()) {
                throw new DomainRuleViolation(
                    sprintf(
                        'Cannot spend %d bonus minor units: the limit for this order is %d.',
                        $requestedBonus,
                        $bonusCap->minorUnits()
                    ),
                    'BONUS_LIMIT_EXCEEDED',
                    [
                        'requested' => $requestedBonus,
                        'available' => $bonusCap->minorUnits(),
                        'subtotal_amount' => $subtotalAmount,
                    ],
                );
            }

            // Итог считает `OrderTotals`, а не арифметика на месте. Это не
            // косметика: `total = subtotal - discount - bonus + fee` — инвариант,
            // продублированный CHECK'ом `ck_orders_amounts`, и до фазы 2b он был
            // записан здесь ТРЕТЬИМ способом (после `OrderPricing` и `OrderTotals`).
            // Клампы живут в объекте, поэтому «бонусов больше, чем заказ» здесь
            // не может дать отрицательный итог.
            $totals = OrderTotals::of(
                Money::of($subtotalAmount, $currency),
                Money::of($discountAmount, $currency),
                Money::zero($currency),
                $promoEvaluation,
                Money::of($requestedBonus, $currency),
            );

            $discountAmount = $totals->discount()->minorUnits();
            $bonusAmount = $totals->bonus()->minorUnits();
            $totalAmount = $totals->total()->minorUnits();

            $order = Order::create([
                'organization_id' => $organizationId,
                // Владелец заказа — вычислен выше (`resolveBuyerId`): сотрудник,
                // вошедший покупатель или аккаунт, заведённый по адресу заказа.
                // Без владельца заказ невидим в `GET /orders` (список скоупится
                // по владельцу) и недоступен поддержке покупателя.
                'user_id' => $buyerId,
                'status' => 'pending',
                'payment_status' => 'pending',
                'subtotal_amount' => $subtotalAmount,
                'discount_amount' => $discountAmount,
                // Списано бонусами — отдельной колонкой, а не внутри скидки:
                // покупатель видит строку, а разбор заказа отличает код от
                // лояльности (см. `OrderTotals`).
                'bonus_amount' => $bonusAmount,
                'fee_amount' => 0,
                'total_amount' => $totalAmount,
                'currency' => $cart->currency ?? 'RUB',
                // A6-цепочка «заказ → оплата → билет»: заказ помнит корзину,
                // сеанс и событие, из которых вырос. PaymentService использует
                // orders.cart_id для валидации холдов, а TicketService берёт
                // session_id/event_id (NOT NULL в таблице tickets) именно отсюда.
                // Без этих полей билеты не выпускались никогда — проверено по коду.
                'cart_id' => $cart->id,
                'session_id' => $session?->id,
                'event_id' => $event?->id,
                'customer_email' => (string) ($customer['customer_email'] ?? ''),
                'customer_name' => $customer['customer_name'] ?? null,
                'customer_phone' => $customer['customer_phone'] ?? null,
            ]);

            foreach ($cart->items as $item) {
                $inventoryItem = $item->inventoryItem;
                OrderItem::create([
                    'order_id' => $order->id,
                    'inventory_item_id' => $inventoryItem?->id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_amount' => $item->total_price,
                    'event_title_snapshot' => $event?->title,
                    'session_title_snapshot' => $session?->title,
                    'venue_title_snapshot' => $session?->venue?->name,
                ]);
            }

            // Списание — ПОСЛЕ создания заказа, потому что строка журнала
            // ссылается на `order_id` (FK `fk_bonus_transactions_order`), и в ЭТОЙ
            // ЖЕ транзакции. Отсюда два свойства, которые и нужны: откат
            // оформления откатывает списание («заказ не создался, а бонусы
            // ушли» невозможно), а заказ без списания невозможен наоборот,
            // потому что `bonus_amount` уже записан выше из того же расчёта.
            //
            // Баланс уменьшается СЕЙЧАС, а не при оплате. Так баланс остаётся
            // единственным источником правды: он же показывается в шапке, и по
            // нему же считается лимит следующего заказа. Если бы списание
            // откладывалось до `markPaid`, один и тот же баланс обеспечил бы
            // списание в двух незакрытых заказах. Неоплаченный заказ освобождает
            // бонусы так же, как места, — `BonusService::refundForOrder()`.
            if ($bonusAmount > 0) {
                $this->bonus->spendForOrder(
                    $order,
                    Money::of($bonusAmount, $currency),
                    Money::of($subtotalAmount, $currency),
                    Money::of($discountAmount, $currency),
                );
            }

            // Расход кода — ПОСЛЕ создания заказа: redeem() пишет redemption со
            // ссылкой на order_id. Счётчик двигается условным UPDATE (см.
            // PromoCodeService::redeem), и весь блок — в этой же транзакции:
            // откат заказа откатывает и использование кода. Анонимная корзина
            // (user_id null) лимит на покупателя не расходует — per_user_limit
            // считается по аутентифицированным пользователям.
            if ($promoCode !== null && $promoEvaluation !== null) {
                $this->promos->redeem($promoCode, $order, $promoEvaluation, $cart->user_id === null ? null : (int) $cart->user_id);
            }

            // Реферальная атрибуция (фаза 4) — ПОСЛЕ создания заказа: строка
            // приглашения ключуется адресом заказа (`customer_email`) и
            // уточняется аккаунтом (`resolveBuyerId()` выше). В той же
            // транзакции, что и заказ: откат оформления откатывает и
            // приглашение. Награда здесь НЕ выплачивается — она ждёт оплаты
            // (`OrderReferralObserver` → `RewardReferralJob`), потому что
            // «первая оплата друга» — денежное событие, а не намерение.
            //
            // Неизвестный или устаревший код — молча (см. `ReferralService`):
            // ссылка не промокод, и ломать из-за неё покупку незачем.
            if (!empty($customer['referral_code'])) {
                $this->referrals->attachToOrder($order, (string) $customer['referral_code']);
            }

            return [
                'cart_id' => $cart->id,
                'order_id' => $order->public_id,
                // `session_id` берётся из корзины, а не из параметра входа:
                // второй вход (`checkoutByPublicId`) параметра не имеет.
                'session_id' => (string) $cart->session_id,
                'items' => $cart->items->map(fn($item) => [
                    'inventory_item_id' => $item->inventory_item_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                ])->toArray(),
                'total_amount' => $totalAmount,
                'subtotal_amount' => $subtotalAmount,
                'discount_amount' => $discountAmount,
                'bonus_amount' => $bonusAmount,
                // Порядок поиска валюты: цена.attr есть и у инвентаря. У CARTS
                // колонки currency НЕТ ((INVENTED_FIELDS Cart) — она была бы
                // null, и витрина могла бы показать пустую валюту.
                'currency' => (string) ($order->currency ?? 'RUB'),
            ];
    }

    /**
     * Восстановить ответ уже ОФОРМЛЕННОЙ корзины (D-8/S8).
     *
     * Ответ собирается из ЗАКАЗА, а не из корзины: у конвертированной корзины
     * позиции уже переехали в order_items, и их перечень источник истины —
     * заказ. Возвращается с флагом `replayed: true`, чтобы отличить повтор
     * от первичного оформления (наше время — наблюдаемость, а не контракт:
     * ключи ответа совпадают с обычным успехом).
     */
    private function replayConvertedOrder(string $sessionId, string $token): ?array
    {
        $cartId = DB::table('carts')
            ->where('session_id', $sessionId)
            ->where('cart_token', $token)
            ->where('status', 'converted')
            ->orderByDesc('id')
            ->value('id');

        if ($cartId === null) {
            return null;
        }

        $order = Order::query()
            ->where('cart_id', $cartId)
            ->orderBy('id')
            ->with('items')
            ->first();

        if ($order === null) {
            return null;
        }

        return [
            'cart_id' => (int) $cartId,
            'order_id' => (string) $order->public_id,
            'session_id' => (string) $sessionId,
            'items' => $order->items
                ->map(fn (\Nabilet\Modules\Orders\Models\OrderItem $item) => [
                    'inventory_item_id' => (int) $item->inventory_item_id,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (int) $item->unit_price,
                    'total_price' => (int) $item->total_price,
                ])
                ->toArray(),
            'total_amount' => (int) $order->total_amount,
            'subtotal_amount' => (int) $order->subtotal_amount,
            'discount_amount' => (int) $order->discount_amount,
            'bonus_amount' => (int) ($order->bonus_amount ?? 0),
            'currency' => (string) ($order->currency ?? 'RUB'),
            'replayed' => true,
        ];
    }
}
