<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Nabilet\Core\Errors\AuthError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\Paging;
use Nabilet\Modules\Agent\Mcp\NabiletAgentServer;
use Nabilet\Modules\Agent\Mcp\Tools\ContentTool;
use Nabilet\Modules\Agent\Mcp\Tools\DiagnosticsTool;
use Nabilet\Modules\Agent\Models\AgentAction;
use Nabilet\Modules\Agent\Services\AgentActionService;
use Nabilet\Modules\Agent\Support\AgentCapabilities;
use Nabilet\Modules\Agent\Support\AgentScope;
use Nabilet\Modules\Core\Users\Support\StaffRole;
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

    /**
     * Данные для раздела «Подключение»: адрес MCP, конфиг клиента и реестр
     * инструментов. Состав берётся из самого сервера через reflection — вторая
     * ручная копия списка инструментов неизбежно разошлась бы с реестром.
     */
    public function connection(Request $request): JsonResponse
    {
        $property = new \ReflectionProperty(NabiletAgentServer::class, 'tools');
        /** @var array<int, class-string> $classes */
        $classes = $property->getDefaultValue();
        $groups = ['core' => [], 'diagnostics' => [], 'content' => []];

        foreach ($classes as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            $instance = new $class();
            $group = is_subclass_of($class, DiagnosticsTool::class)
                ? 'diagnostics'
                : (is_subclass_of($class, ContentTool::class) ? 'content' : 'core');
            $groups[$group][] = [
                'name' => $instance->name(),
                'description' => $instance->description(),
                'read_only' => (new \ReflectionClass($class))->getAttributes(IsReadOnly::class) !== [],
            ];
        }

        $routeRegistered = true;
        try {
            Route::getRoutes()->match(Request::create('/mcp', 'POST'));
        } catch (\Throwable) {
            $routeRegistered = false;
        }

        $activeTokens = 0;
        $scope = AgentScope::resolve($request->user());
        if ($scope !== null) {
            $userIds = DB::table('user_organization')
                ->whereIn('organization_id', $scope->organizationIds)
                ->pluck('user_id')
                ->all();
            $activeTokens = (int) DB::table('personal_access_tokens')
                ->whereIn('tokenable_id', $userIds)
                ->where('tokenable_type', User::class)
                ->where('abilities', 'like', '%agent:read%')
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->count();
        }

        $ok = $routeRegistered && $activeTokens > 0;

        return response()->json(['data' => [
            'endpoint' => url('/mcp'),
            'transport' => 'Streamable HTTP',
            'auth_header' => 'Authorization: Bearer <токен>',
            'capabilities' => AgentCapabilities::labels(),
            'groups' => $groups,
            'client_config' => [
                'mcp' => [
                    'nabilet' => [
                        'type' => 'remote',
                        'url' => url('/mcp'),
                        'headers' => ['Authorization' => 'Bearer {env:NABILET_AGENT_TOKEN}'],
                        'enabled' => true,
                    ],
                ],
            ],
            'rate_limit' => '60 запросов/мин на токен',
            'checks' => [
                'route_registered' => $routeRegistered,
                'active_tokens' => $activeTokens,
                'ok' => $ok,
                'message' => $ok
                    ? 'Маршрут /mcp доступен и есть активный токен с agent:read.'
                    : (!$routeRegistered
                        ? 'Маршрут /mcp не зарегистрирован: проверьте маршруты приложения.'
                        : 'Нет активного токена с agent:read — выпустите токен выше.'),
            ],
            'docs' => 'docs/MCP-INTEGRATION.md',
        ]]);
    }

    public function issueToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'abilities' => ['required', 'array', 'min:1', 'max:8'],
            'abilities.*' => ['string', 'max:64'],
        ]);

        $owner = $this->resolveOwner($request, $validated['email'] ?? null);
        $scope = AgentScope::resolve($request->user());
        if ($scope === null) {
            throw new ValidationError(['email' => ['Нет организации для выпуска токена.']]);
        }

        // Полномочия назначаются ЯВНО: agent:read больше не добавляется молча.
        // Иначе токен «только для диагностики» получил бы доступ к заказам.
        $abilities = array_values(array_unique($validated['abilities']));
        foreach ($abilities as $ability) {
            if (! AgentCapabilities::isKnown($ability)) {
                throw new ValidationError(['abilities' => ['Неизвестная способность: ' . $ability]]);
            }
        }

        $this->guardSystemRead($request->user(), $abilities, $owner);

        $token = $owner->createToken((string) $validated['name'], $abilities);

        return response()->json(['data' => [
            'id' => (int) $token->accessToken->id,
            'name' => (string) $validated['name'],
            'abilities' => $abilities,
            'token' => $token->plainTextToken,
        ]], 201);
    }

    /**
     * Глобальная диагностика — не «ещё одна способность админки».
     *
     * Её может выдать только системный администратор и только системному
     * администратору. Иначе обычный админ организации создаст токен с
     * agent:system:read «на вырост»: как только его e-mail добавят в
     * NABILET_PLATFORM_ADMINS, старый токен молча получит доступ к логам,
     * бэкапам и глобальным настройкам всех арендаторов.
     *
     * @param list<string> $abilities
     */
    private function guardSystemRead(?\Illuminate\Contracts\Auth\Authenticatable $actor, array $abilities, User $owner): void
    {
        if (! in_array(AgentCapabilities::SYSTEM_READ, $abilities, true)) {
            return;
        }
        if (! StaffRole::isPlatformAdmin($actor) || ! StaffRole::isPlatformAdmin($owner)) {
            throw AuthError::forbidden('agent:system:read');
        }
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
        $this->guardSystemRead($request->user(), $abilities, $owner);
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
