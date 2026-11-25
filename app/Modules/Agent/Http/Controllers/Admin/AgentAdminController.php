<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\Paging;
use Nabilet\Modules\Agent\Models\AgentAction;
use Nabilet\Modules\Agent\Services\AgentActionService;
use Nabilet\Modules\Agent\Support\AgentCapabilities;
use Nabilet\Modules\Agent\Support\AgentScope;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Управление ИИ-агентом из админки — без SSH и artisan на шаред-хостинге.
 *
 * Токены выдаются здесь же, где их отзывают: plaintext показывается ровно
 * один раз в ответе create/rotate, в списке — только имя, способности и даты.
 * Write-действия агента попадают в очередь ожидания и исполняются только
 * после явного одобрения человеком (денежные — с отдельной фразой REFUND).
 */
final class AgentAdminController extends Controller
{
    public function __construct(
        private readonly AgentActionService $actions,
    ) {}

    public function tokens(Request $request): JsonResponse
    {
        $scope = AgentScope::resolve($request->user());
        if ($scope === null) {
            return response()->json(['data' => []]);
        }

        $userIds = DB::table('user_organization')
            ->whereIn('organization_id', $scope->organizationIds)
            ->pluck('user_id')
            ->all();

        $tokens = DB::table('personal_access_tokens')
            ->whereIn('tokenable_id', $userIds)
            ->where('tokenable_type', User::class)
            ->where('abilities', 'like', '%agent:%')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $t): array => [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
                'abilities' => json_decode((string) $t->abilities, true) ?: [],
                'created_at' => $t->created_at,
                'last_used_at' => $t->last_used_at,
                'expires_at' => $t->expires_at,
            ])
            ->all();

        return response()->json(['data' => $tokens]);
    }

    public function issueToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'abilities' => ['required', 'array', 'min:1', 'max:5'],
            'abilities.*' => ['string', 'max:64'],
        ]);

        $owner = $this->resolveOwner($request, $validated['email'] ?? null);
        $scope = AgentScope::resolve($request->user());
        if ($scope === null) {
            throw new ValidationError(['email' => ['Нет организации для выпуска токена.']]);
        }

        $abilities = array_values(array_unique(array_merge([AgentCapabilities::READ], $validated['abilities'])));
        foreach ($abilities as $ability) {
            if (! AgentCapabilities::isKnown($ability)) {
                throw new ValidationError(['abilities' => ['Неизвестная способность: ' . $ability]]);
            }
        }

        $token = $owner->createToken((string) $validated['name'], $abilities);

        return response()->json(['data' => [
            'id' => (int) $token->accessToken->id,
            'name' => (string) $validated['name'],
            'abilities' => $abilities,
            'token' => $token->plainTextToken,
        ]], 201);
    }

    public function revokeToken(Request $request, int $token): JsonResponse
    {
        $row = $this->findToken($request, $token);
        DB::table('personal_access_tokens')->where('id', $token)->delete();

        return response()->json(['data' => ['revoked' => $row->name, 'id' => $token]]);
    }

    public function rotateToken(Request $request, int $token): JsonResponse
    {
        $row = $this->findToken($request, $token);
        $abilities = json_decode((string) $row->abilities, true) ?: [AgentCapabilities::READ];

        $owner = User::query()->findOrFail((int) $row->tokenable_id);
        DB::table('personal_access_tokens')->where('id', $token)->delete();
        $issued = $owner->createToken((string) $row->name, $abilities);

        return response()->json(['data' => [
            'id' => (int) $issued->accessToken->id,
            'name' => (string) $row->name,
            'abilities' => $abilities,
            'token' => $issued->plainTextToken,
        ]]);
    }

    public function pendingActions(Request $request): JsonResponse
    {
        $scope = AgentScope::resolve($request->user());
        if ($scope === null) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $perPage = Paging::limit((int) $request->query('per_page', '50'), 50, 100);
        $query = AgentAction::query()
            ->whereIn('organization_id', $scope->organizationIds)
            ->orderByDesc('id');

        if ($request->query('status') !== null) {
            $query->where('status', (string) $request->query('status'));
        } else {
            $query->whereIn('status', [
                AgentAction::STATUS_PENDING,
                AgentAction::STATUS_APPROVED,
                AgentAction::STATUS_EXECUTED,
                AgentAction::STATUS_FAILED,
                AgentAction::STATUS_REJECTED,
            ]);
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function approve(Request $request, int $action): JsonResponse
    {
        $validated = $request->validate([
            'confirm_phrase' => ['nullable', 'string', 'max:32'],
        ]);

        $this->actions->approve($this->findAction($request, $action), $request->user(), $validated['confirm_phrase'] ?? null);

        return response()->json(['data' => ['status' => AgentAction::STATUS_APPROVED]]);
    }

    public function reject(Request $request, int $action): JsonResponse
    {
        $this->actions->reject($this->findAction($request, $action), $request->user());

        return response()->json(['data' => ['status' => AgentAction::STATUS_REJECTED]]);
    }

    private function findAction(Request $request, int $id): AgentAction
    {
        $scope = AgentScope::resolve($request->user());
        $action = AgentAction::query()->find($id);
        if ($action === null || $scope === null
            || ! in_array((int) $action->organization_id, $scope->organizationIds, true)) {
            throw new NotFoundError('AgentAction', (string) $id);
        }

        return $action;
    }

    private function findToken(Request $request, int $id): object
    {
        $scope = AgentScope::resolve($request->user());
        if ($scope === null) {
            throw new NotFoundError('PersonalAccessToken', (string) $id);
        }
        $userIds = DB::table('user_organization')
            ->whereIn('organization_id', $scope->organizationIds)
            ->pluck('user_id')
            ->all();

        $row = DB::table('personal_access_tokens')
            ->where('id', $id)
            ->whereIn('tokenable_id', $userIds)
            ->where('tokenable_type', User::class)
            ->where('abilities', 'like', '%agent:%')
            ->first();

        if ($row === null) {
            throw new NotFoundError('PersonalAccessToken', (string) $id);
        }

        return $row;
    }

    private function resolveOwner(Request $request, ?string $email): User
    {
        if ($email === null) {
            return User::query()->findOrFail((int) $request->user()->getAuthIdentifier());
        }
        $scope = AgentScope::resolve($request->user());
        $owner = User::query()->where('email', $email)->first();
        if ($owner === null || $scope === null
            || ! DB::table('user_organization')
                ->where('user_id', $owner->id)
                ->whereIn('organization_id', $scope->organizationIds)
                ->exists()) {
            throw new ValidationError(['email' => ['Пользователь не найден в ваших организациях.']]);
        }

        return $owner;
    }
}
