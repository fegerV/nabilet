<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Loyalty\Http\Resources\EventFavoriteResource;
use Nabilet\Modules\Loyalty\Services\FavoriteService;

/**
 * «❤️ Избранное» — личный список «хочу посмотреть».
 *
 * Все методы читают владельца только из `auth:api`: ни один не принимает
 * чужой идентификатор пользователя, поэтому подмена чужого избранного
 * невозможна в принципе, а не «проверяется в коде».
 */
class FavoriteController extends LoyaltyController
{
    public function __construct(private readonly FavoriteService $favorites)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => EventFavoriteResource::collection($this->favorites->forUser($user)),
        ]);
    }

    /**
     * Добавить в избранное. Первое нажатие — 201, повторное — 200 с той же
     * строкой: `uq_event_favorites_user_event` уже зафиксировала этот факт, и
     * ошибка на втором «❤️» была бы ложной. Разница в коде ответа — не
     * украшение: 201 говорит «создано», и вызывающий вправе по нему решать,
     * показывать ли тост «Добавлено в избранное» второй раз.
     */
    public function store(Request $request, string $event): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $event = $this->resolveEvent($event);
        $existed = $this->favorites->isFavorite($user, $event);

        $favorite = $this->favorites->add($user, $event);

        return response()->json([
            'data' => new EventFavoriteResource($favorite->load('event')),
        ], $existed ? 200 : 201);
    }

    /**
     * Убрать из избранного. 204 и на повторное нажатие: отсутствие отметки —
     * это и есть то состояние, которого просили достичь.
     */
    public function destroy(Request $request, string $event): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->favorites->remove($user, $this->resolveEvent($event));

        return response()->json(null, 204);
    }
}
