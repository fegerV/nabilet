<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Http\Resources\RaffleResultsResource;
use Nabilet\Modules\Loyalty\Models\Raffle;
use Nabilet\Modules\Loyalty\Services\RaffleService;
use Nabilet\Modules\Loyalty\StateMachines\RaffleStateMachine;

/**
 * Розыгрыши «счастливый билет» на витрине (фаза 5).
 *
 * ДВА ПУТИ С РАЗНЫМ ДОСТУПОМ
 *   `index()` — кабинет покупателя под `auth:api`: «в каких розыгрышах есть
 *   мои билеты». `results()` — публичная страница результатов: её открывают
 *   без аккаунта, и наружу уходит только маска победителя
 *   (`RaffleResultsResource`).
 *
 * ПОЧЕМУ ЧЕРНОВИКИ И ОТМЕНЁННЫЕ НЕ ВИДНЫ НИГДЕ
 *   Черновик ещё не существует для покупателя (он в процессе настройки),
 *   отменённый закончился ничьей. Показывать «вы участвовали» в отменённом
 *   розыгрыше — обещать приз, который не будет; смотреть «результаты»
 *   черновика — нечего. Отбор по `RaffleStateMachine::visible()`.
 */
class RaffleController extends Controller
{
    public function __construct(
        private readonly RaffleService $raffles,
    ) {
    }

    /**
     * GET /api/v1/me/raffles — мои участия.
     *
     * Пустой список — не 404: покупатель без билетов не ошибся, у него просто
     * ещё нет розыгрышей. Ответ всегда 200 с `data: []`.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->raffles->myRaffles($user),
        ]);
    }

    /**
     * GET /api/v1/raffles/{public_id} — результаты розыгрыша (публично).
     *
     * 404 для черновика, отменённого и несуществующего: у всех трёх случаев
     * один смысл для посетителя — «этой страницы нет».
     */
    public function results(string $publicId): JsonResponse
    {
        $raffle = $this->raffles->findByPublicId($publicId);

        if ($raffle === null || !in_array((string) $raffle->status, RaffleStateMachine::visible(), true)) {
            throw new NotFoundError('Raffle', $publicId);
        }

        // Счётчик участников грузится тем же ответом: страница показывает
        // «билетов было N», и отдельный запрос под него — лишний круг.
        $raffle->loadCount('entries');

        return RaffleResultsResource::make($raffle->load(['orderedPrizes', 'winners']))->response();
    }
}
