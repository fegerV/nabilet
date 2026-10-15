<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Http\Resources\BonusBalanceResource;
use Nabilet\Modules\Loyalty\Http\Resources\BonusTransactionResource;
use Nabilet\Modules\Loyalty\Models\BonusTransaction;
use Nabilet\Modules\Loyalty\Services\BonusService;

/**
 * «Бонусный счёт» — баланс и история движений покупателя.
 *
 * Владелец всегда берётся из `auth:api` и никогда из query/path: ни один метод
 * не принимает идентификатор пользователя, поэтому прочитать чужой баланс
 * неоткуда. Это тот же инвариант, что у `FavoriteController`, и по той же
 * причине — путь обещает «мой», и подставить чужое в него нечем.
 *
 * Счёт заводится лениво (`BonusAccount::forUserOrCreate()`), поэтому «нет
 * счёта» — это не 404, а ноль бонусов: покупатель без единого заказа обязан
 * увидеть «0 бонусов», а не ошибку.
 */
class BonusController extends Controller
{
    public function __construct(private readonly BonusService $bonus)
    {
    }

    /**
     * Баланс. `Money` приходит и из журнала, и из кэша счёта, но наружу уходит
     * одной формой (`BonusBalance`), поэтому два представления баланса —
     * кабинет и шапка — не могут разойтись.
     */
    public function balance(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => new BonusBalanceResource($this->bonus->balanceFor($user)),
        ]);
    }

    /**
     * История движений, свежие сверху.
     *
     * `data` — СПИСОК, а не объект пагинатора: схема
     * `BonusTransactionListResponse` объявляет `data: array<BonusTransaction>` +
     * `meta`. `response()->json(['data' => $paginator])` отдал бы
     * `{"data":{"current_page":1,"data":[…]}}` — список оказывается на два
     * уровня глубже обещанного, и клиент, читающий `data[0].delta`, получает
     * `undefined`. Тот же приём, что у `OrderController::mine()`.
     */
    public function transactions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $transactions = BonusTransaction::query()
            ->forUser($user->getKey())
            // Заказ нужен ради `public_id` в ответе; без `with` ресурс вернул бы
            // ключ `order_id` отсутствующим, а не `null`.
            ->with('order')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => BonusTransactionResource::collection($transactions->items())->resolve($request),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'last_page' => $transactions->lastPage(),
            ],
        ]);
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->get('per_page', 25)));
    }
}
