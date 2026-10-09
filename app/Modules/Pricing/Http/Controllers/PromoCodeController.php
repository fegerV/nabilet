<?php

declare(strict_types=1);

namespace Nabilet\Modules\Pricing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\TenantContextMissingError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Orders\Models\PromoCode;
use Nabilet\Modules\Pricing\Http\Resources\PromoCodeResource;
use Nabilet\Modules\Pricing\Services\PromoCodeService;

/**
 * Промокоды: админский CRUD + публичная валидация против корзины.
 *
 * Контракт: docs/openapi.yaml, `/api/v1/promo-codes` (list/create/get/patch/
 * delete под bearerAuth+admin) и `/api/v1/promo-codes/validate` — единственный
 * публичный путь группы: покупатель проверяет код ДО оформления, когда
 * корзины у него может не быть, а авторизации — тем более.
 *
 * ПОЧЕМУ В АУДИТО ЭТОГО СЛОЯ НЕ БЫЛО ВООБЩЕ
 *
 * Модуль Pricing содержал только домен (`PromoEvaluator` и компания) и
 * провайдер-заглушку: таблицы `promo_codes`/`promo_code_redemptions`
 * существовали с миграции продаж, движок умел оценивать код, но ни один HTTP
 * путь контракта не был зарегистрирован — все шесть отвечали 404, а checkout
 * честно отказывал кодом PROMO_CODE_NOT_SUPPORTED.
 */
class PromoCodeController extends Controller
{
    public function __construct(
        private readonly PromoCodeService $promos,
        private readonly OrganizationContext $context,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', '25')));
        $page = max(1, (int) $request->query('page', '1'));

        $paginator = $this->promos->listForOrganization($this->organizationId(), $perPage, $page);

        return response()->json([
            'data' => PromoCodeResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $promoCode): JsonResponse
    {
        return response()->json([
            'data' => new PromoCodeResource($this->promos->findForOrganization($this->organizationId(), $promoCode)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertRequired($request, ['code', 'discount_type']);

        // Тот же white-list контракта PromoCodeCreate, что и в update():
        // redemptions_count / organization_id не должны проходить из тела
        // запроса даже на создании.
        static $allowed = [
            'code', 'discount_type', 'value_amount', 'value_percent', 'currency',
            'scope', 'event_id', 'event_category_id', 'min_order_amount',
            'max_redemptions', 'per_user_limit', 'status', 'valid_from', 'valid_until',
        ];

        $code = $this->promos->create($this->organizationId(), $request->only($allowed));

        return response()->json(['data' => new PromoCodeResource($code)], 201);
    }

    /**
     * Обновить код (PATCH-семантика: отсутствуют поля — остаются как были).
     */
    public function update(Request $request, string $promoCode): JsonResponse
    {
        $code = $this->promos->findForOrganization($this->organizationId(), $promoCode);

        // White-list контракта PromoCodeUpdate. `$request->all()` сюда не
        // проходит: он позволил бы протащить redemptions_count (счётчик расхода
        // — прямое подделывание лимита), organization_id (межтенантовый
        // перенос) и произвольные колонки схемы.
        static $allowed = [
            'code', 'discount_type', 'value_amount', 'value_percent',
            'scope', 'event_id', 'event_category_id', 'min_order_amount',
            'max_redemptions', 'per_user_limit', 'status', 'valid_from', 'valid_until',
        ];

        $input = $request->only($allowed);

        $code = $this->promos->update($code, $input);

        return response()->json(['data' => new PromoCodeResource($code)]);
    }

    public function destroy(string $promoCode): JsonResponse
    {
        $this->promos->delete($this->promos->findForOrganization($this->organizationId(), $promoCode));

        return response()->json(status: 204);
    }

    /**
     * POST /promo-codes/validate — без авторизации, БЕЗ расхода кода.
     *
     * Контекст скидки берётся из корзины (`cart_id` или `cart_token` — то, что
     * реально есть у покупателя витрины), либо из явного `order_amount`, если
     * корзины ещё нет. Второй вариант не умеет проверять scope=event/category:
     * по одной сумме нельзя понять, ЧТО именно в корзине, поэтому такие коды
     * при оценке вслепую отвергаются честной причиной, а не молча применяются.
     *
     * АРЕНДАТОР. Путь публичный: `ResolveOrganizationContext` заполняет контекст
     * только из аутентифицированного пользователя или host-маппинга, поэтому
     * здесь он может быть пуст. Молчаливый откат «на первую организацию в БД»
     * означал бы, что покупатель с кодом чужой организации получает её цены и
     * существование её кодов (oracle), а админ без контекста начал бы работать
     * с чужим tenant'ом. Поэтому контекст обязателен: без него — честный
     * TENANT_CONTEXT_MISSING, а не угадывание.
     *
     * Имя метода — `check`, а не `validate`: Illuminate Controller уже имеет
     * финальный `validate(Validator)` для трейта AuthorizesRequests, и метод с
     * тем же именем — фатальная ошибка объявления.
     */
    public function check(Request $request): JsonResponse
    {
        $code = trim((string) $request->input('code', ''));

        if ($code === '') {
            throw new ValidationError(['code' => ['The code field is required.']], 'Promo code is required.');
        }

        $promo = $this->promos->findByCode($this->organizationId(), $code);

        if ($promo === null) {
            // Не «не найден», а «не применим»: контракт описывает ответ 200 с
            // valid:false. Иначе эндпоинт стал бы оракулом «существует ли такой
            // код в этой организации».
            return response()->json(['data' => [
                'valid' => false,
                'discount_amount' => 0,
                'rejection_reason' => 'not_found',
            ]]);
        }

        $cart = $this->resolveCart($request);

        if ($cart !== null) {
            return response()->json(['data' => $this->promos->validateForCart($promo, $cart)]);
        }

        $orderAmount = $request->input('order_amount');

        if ($orderAmount === null) {
            throw new ValidationError(
                ['cart_id' => ['Provide cart_id (or cart_token) or order_amount.']],
                'Validation context is required.'
            );
        }

        // Оценка вслепую: одна виртуальная строка на всю сумму, scope=all.
        // Минимальная сумма, окно действия и лимиты проверяются evaluator'ом;
        // event/category-скоуп при отсутствии строк закономерно отвергается.
        $evaluation = $this->promos->evaluateForCart($promo, $this->syntheticCart((int) $orderAmount));

        return response()->json(['data' => $evaluation->jsonSerialize()]);
    }

    private function resolveCart(Request $request): ?Cart
    {
        $token = trim((string) $request->input('cart_token', ''));

        if ($token !== '') {
            $cart = Cart::query()->where('cart_token', $token)->where('status', 'active')->first();

            if ($cart === null) {
                // NotFoundError(resource, resourceId): код составляется классом
                // ('cart' -> CART_NOT_FOUND), идентификатор — в сообщении.
                throw new NotFoundError('cart', $token);
            }

            return $cart;
        }

        $cartId = $request->input('cart_id');

        if (is_string($cartId) && ctype_digit($cartId)) {
            $cart = Cart::query()->whereKey((int) $cartId)->where('status', 'active')->first();

            if ($cart === null) {
                throw new NotFoundError('cart', $cartId);
            }

            return $cart;
        }

        return null;
    }

    /**
     * Псевдокорзина для оценки по сумме без состава.
     *
     * Объект создан НЕ через БД — только поля, которые читает
     * `PromoCodeService::buildContext()` (currency, items, user_id). Это
     * вынужденная мера: переписывать evaluator на приём «голой суммы» значило
     * бы удвоить правила §86 во втором месте.
     */
    private function syntheticCart(int $orderAmount): Cart
    {
        $cart = new Cart();
        $cart->forceFill(['currency' => 'RUB', 'user_id' => null, 'total_amount' => (string) $orderAmount]);

        // buildContext() итерировать items() — пустая коллекция дала бы нулевой
        // подытог, а min_order_amount сравнивается с ним. Поэтому строка
        // одна, ровно на запрошенную сумму: evaluator увидит subtotal ==
        // order_amount, что для scope=all корректно по определению.
        // inventory_item_id = null: строка несуществующая, и нулевой id ударил
        // бы по внешнему ключу cart_items — здесь связь только in-memory, но
        // null честнее фиктивного «места №0».
        $cart->setRelation('items', new \Illuminate\Database\Eloquent\Collection([
            new \Nabilet\Modules\Cart\Models\CartItem([
                'quantity' => 1,
                'unit_price' => (string) $orderAmount,
                'total_price' => (string) $orderAmount,
            ]),
        ]));

        return $cart;
    }

    /**
     * @param list<string> $fields
     */
    private function assertRequired(Request $request, array $fields): void
    {
        $errors = [];

        foreach ($fields as $field) {
            if (! $request->filled($field)) {
                $errors[$field] = [sprintf('The %s field is required.', $field)];
            }
        }

        if ($errors !== []) {
            throw new ValidationError($errors);
        }
    }

    /**
     * Активный арендатор — строго из контекста запроса.
     *
     * БЕЗ отката «первая организация в БД». Дыра была двусторонней:
     *   - публичный /validate без tenant-контекста попадал бы в произвольный
     *     (первый по id) tenant и отвечал valid:true/valid:false по чужим
     *     кодам — это oracle существования и утечка скидок между организациями;
     *   - админский CRUD без контекста молча начинал жить в чужом tenant'е.
     * OrganizationContext спроектирован fail-closed (`id()` бросает
     * TenantContextMissingError), и контроллер обязан следовать этому правилу,
     * а не обходить его собственным fallback'ом.
     */
    private function organizationId(): int
    {
        $contextId = $this->context->id(PromoCode::class);

        if (! ctype_digit($contextId)) {
            throw new TenantContextMissingError(PromoCode::class);
        }

        return (int) $contextId;
    }
}
