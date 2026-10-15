<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Http\Resources\EventFollowResource;
use Nabilet\Modules\Loyalty\Services\FollowService;

/**
 * «🔔 Подписки» — просьба присылать письмо о новых сеансах мероприятия.
 *
 * Отличие от избранного — назначение: подписка порождает письма. Сама
 * рассылка (новый сеанс → `follow_notifications` → письмо) описана в
 * `docs/LOYALTY-PLAN.md`, фаза 1, и живёт вне этого контроллера: он только
 * управляет списком подписчиков.
 */
class FollowController extends LoyaltyController
{
    public function __construct(private readonly FollowService $follows)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => EventFollowResource::collection($this->follows->forUser($user)),
        ]);
    }

    /**
     * Подписаться. Первое нажатие — 201, повторное — 200 с той же подпиской,
     * ровно как у избранного: `uq_event_follows_user_event` уже зафиксировала
     * факт, и разница в коде ответа говорит вызывающему, показывать ли тост
     * «Вы подписаны» второй раз.
     */
    public function store(Request $request, string $event): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $event = $this->resolveEvent($event);
        $existed = $this->follows->isFollowing($user, $event);

        $follow = $this->follows->subscribe($user, $event);

        return response()->json([
            'data' => new EventFollowResource($follow->load('event')),
        ], $existed ? 200 : 201);
    }

    /**
     * Отписаться. 204 и на повторное нажатие: «сейчас вы не подписаны» — это
     * и есть запрошенное состояние.
     */
    public function destroy(Request $request, string $event): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->follows->unsubscribe($user, $this->resolveEvent($event));

        return response()->json(null, 204);
    }
}
