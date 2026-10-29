<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Core\Support\UserOrganization;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active organization BEFORE anything tenant-scoped runs.
 *
 * Order matters: this middleware must sit before authorization and before any
 * controller. If a policy or a repository query executes first, it does so without a
 * tenant context — and in a fail-closed system that is an exception, while in a
 * permissive system it is a cross-tenant data leak.
 *
 * Resolution strategy:
 *   1. An authenticated user's organization wins.
 *   2. A `Host`-based subdomain mapping (white-label deployments).
 *   3. Paths in `nabilet.tenancy.system_paths` (installer, health, webhooks,
 *      sitemap) run with an explicit bypass instead of a guess.
 *
 * The bypass is granted through `withoutScope()`, so it is visible in code review
 * rather than implicit.
 */
final class ResolveOrganizationContext
{
    public function __construct(private readonly OrganizationContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Каждый запрос начинается с чистого контекста. Модуль — singleton
        // приложения, и в долгоживущем процессе (Octane, тестовый HTTP-клиент,
        // где несколько запросов идут через один контейнер) значение прошлого
        // запроса иначе переживёт своего пользователя: следующий запрос без
        // членства унаследовал бы чужую организацию.
        $this->context->clear();

        if ($this->isSystemPath($request)) {
            return $this->context->withoutScope(fn (): Response => $next($request));
        }

        $organizationId = $this->resolveFromUser($request) ?? $this->resolveFromHost($request);

        // Deliberately no "default organization" fallback: guessing here would mean
        // an unauthenticated request lands in whichever tenant happens to be first.
        if ($organizationId !== null) {
            $this->context->set($organizationId);
        }

        return $next($request);
    }

    private function resolveFromUser(Request $request): ?string
    {
        $user = $request->user() ?? $this->apiGuardUser($request);

        if ($user === null) {
            return null;
        }

        // Членство спрашиваем у `UserOrganization` — того же владельца, которым
        // пользуются контроллеры событий, площадок, вебхуков и витрины.
        //
        // В ТАБЛИЦЕ `users` КОЛОНКИ `organization_id` НЕТ: членство сотрудника
        // живёт в связке `user_organization` (установщик привязывает админа
        // именно туда — см. `InstallerController::bootstrapOrganization()`, а
        // его докблок прямо обещает, что «ResolveOrganizationContext resolves
        // the active org from the user's membership»). Ветка на несуществующую
        // колонку здесь стояла и никогда не срабатывала: guard отдаёт обычную
        // модель `User`, без join'ов. Пока читалась только она, контекст у
        // сотрудника оставался пустым ВСЕГДА, и любой tenant-scoped админский
        // путь падал 500 `TenantContextMissingError` — например весь CRUD
        // /promo-codes.
        //
        // Своя выборка из `user_organization` была вторым экземпляром правила
        // «какая организация у пользователя»: сортировка в одном месте могла
        // разойтись с другой, и тогда middleware выбирал бы одну организацию, а
        // контроллер — другую, в рамках одного запроса.
        //
        // Фолбэка на «первую организацию в таблице» здесь нет и быть не должно
        // (см. докблок класса): угадывание означает запись чужому тенанту.
        $organizationId = UserOrganization::id($user);

        return $organizationId === null ? null : (string) $organizationId;
    }

    /**
     * Пользователь bearer-токена API.
     *
     * ПОЧЕМУ НЕ ТОЛЬКО `$request->user()`. Этот middleware стоит в ГЛОБАЛЬНОМ
     * стеке — то есть раньше маршрутного `auth:api`. `$request->user()` смотрит
     * в guard по умолчанию (`web`, сессия браузера), а публичный API
     * авторизуется заголовком `Authorization: Bearer …` через guard `api`
     * (`session_token`). Поэтому для API-запроса `$request->user()` возвращал
     * null даже с валидным токеном, и организация сотрудника не резолвилась
     * НИКОГДА — со всеми последствиями вроде 500 на админском CRUD.
     *
     * Обращаемся к guard явно и только когда токен вообще есть: без заголовка
     * это лишний запрос к `user_sessions` на каждом публичном запросе витрины.
     */
    private function apiGuardUser(Request $request): ?Authenticatable
    {
        if ($request->bearerToken() === null) {
            return null;
        }

        return auth('api')->user();
    }

    /**
     * White-label / partner subdomains, e.g. tickets.partner.ru.
     * Resolution goes through a hook so a plugin can supply its own mapping without
     * a core change.
     */
    private function resolveFromHost(Request $request): ?string
    {
        if (! function_exists('apply_filters')) {
            return null;
        }

        $organizationId = apply_filters('tenancy.resolve_from_host', null, $request->getHost());

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }

    private function isSystemPath(Request $request): bool
    {
        $paths = (array) config('nabilet.tenancy.system_paths', []);

        foreach ($paths as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
