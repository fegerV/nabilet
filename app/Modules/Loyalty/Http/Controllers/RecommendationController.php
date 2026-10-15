<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Domain\RecommendationPolicy;
use Nabilet\Modules\Loyalty\Http\Resources\EventSummaryResource;
use Nabilet\Modules\Loyalty\Services\RecommendationService;

/**
 * «Рекомендуем» — персональная полка предстоящих мероприятий.
 *
 * ВЛАДЕЛЕЦ — ТОЛЬКО ИЗ ТОКЕНА
 *   Как и у остальных путей `/me/*`, метод не принимает `user_id`: владелец
 *   приходит из `auth:api`. Рекомендации — производное от ЛИЧНЫХ
 *   взаимодействий, поэтому подстановка чужого идентификатора невозможна в
 *   принципе, а не «проверяется в коде».
 *
 * ФОРМА ОТВЕТА — ТА ЖЕ, ЧТО У «ИЗБРАННОГО»
 *   Карточка отдаётся `EventSummaryResource` — тем же ресурсом, что и списки
 *   кабинета (id/slug/title/poster/age_limit/starts_at). Отдельная схема под
 *   «Рекомендуем» была бы вторым местом, которое надо править при изменении
 *   карточки, и разошлась бы с кабинетом при первой же правке.
 *
 * ПУСТОЙ СПИСОК — 200, А НЕ 404
 *   У нового покупателя ещё нет ни одного сигнала, и `data: []` честно говорит
 *   «пока нечего рекомендовать». 404 заставил бы витрину трактовать отсутствие
 *   рекомендаций как ошибку и рисовать «не удалось загрузить».
 */
class RecommendationController extends LoyaltyController
{
    public function __construct(private readonly RecommendationService $recommendations)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Лимит — из строки запроса, то есть от клиента. `clampLimit` — то же
        // правило границ, что применяет сервис: неположительное значение значит
        // «по умолчанию», а не ошибку валидации (рекомендации — необязательная
        // витринная полка, ронять страницу из-за неё не за что).
        $limit = RecommendationPolicy::clampLimit((int) $request->query('limit', 0));

        $events = $this->recommendations->forUser($user, $limit);

        return response()->json([
            'data' => EventSummaryResource::collection($events),
        ]);
    }
}
