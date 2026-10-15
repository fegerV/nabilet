<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Http\Resources\LoyaltyLevelStatusResource;
use Nabilet\Modules\Loyalty\Services\LoyaltyLevelService;

/**
 * Текущий уровень «Клуба зрителей» покупателя.
 *
 * Владелец всегда из `auth:api`: эндпоинт закрыт тем же middleware, что и прочие
 * `/me/*` пути (`app/Modules/Loyalty/routes/api.php`), поэтому прочитать чужой
 * уровень неоткуда — метод не принимает ничьего идентификатора.
 *
 * Уровень — производное от числа оплаченных заказов, пересчитываемое обсервером
 * `OrderLoyaltyObserver`; здесь оно лишь собирается в ответ. Гостю сюда ход не
 * открыт (401), а у авторизованного покупателя без заказов ответ — Старт с нулём
 * оплаченных, а не 404.
 */
final class LoyaltyLevelController extends Controller
{
    public function __construct(private readonly LoyaltyLevelService $service)
    {
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => new LoyaltyLevelStatusResource($this->service->statusFor($user)),
        ]);
    }
}
