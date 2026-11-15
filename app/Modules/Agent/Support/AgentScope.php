<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Support;

use Nabilet\Core\Tenancy\OrganizationContext;

/**
 * Организационный скоуп вызова инструмента агента.
 *
 * Каждое чтение агента ограничено организациями владельца токена — тем же
 * правилом, что и у человека-сотрудника (см. `CheckOrganizationAccess`:
 * «доступ к организации, членом которой вызывающий не является, отказан»).
 *
 * ПОЧЕМУ `withoutScope()` + ЯВНЫЙ `whereIn`, А НЕ УСТАНОВКА КОНТЕКСТА
 *
 * `OrganizationContext` хранит ОДНУ организацию на запрос, а владелец токена
 * может состоять в нескольких. Контекст пришлось бы угадывать (`->set()` от
 * первой организации) — а угаданный арендатор показывал бы чужие данные тихо,
 * без ошибки. Поэтому вместо подмены контекста — fail-closed через явный
 * фильтр: запросы выполняются внутри `withoutScope()` (без него модели
 * класса A с включённым читающим скоупом (`sessions`, `api_keys`, …) бросили
 * бы `TenantContextMissingError`), и КАЖДЫЙ запрос обязаны вести
 * `organization_id IN (…)` — метод `filter()` здесь возвращает готовый
 * callable. Пустой список организаций → NULL (инструмент отвечает ошибкой),
 * никогда не «показать всё».
 *
 * Пример:
 *   $scope = AgentScope::resolve($user);
 *   if ($scope === null) { return Response::error('нет организаций'); }
 *   $scope->run(fn (array $orgIds) => $query->whereIn('organization_id', $orgIds)->get());
 */
final class AgentScope
{
    /**
     * @param  list<int>  $organizationIds
     */
    private function __construct(
        public readonly array $organizationIds,
    ) {}

    /**
     * Скоуп вызова по владельцу токена, или null — когда читать нельзя:
     * пользователь не передан (нет аутентификации) или не состоит ни в одной
     * организации (такой токен видит пусто, а не чужие данные).
     */
    public static function resolve(?object $user): ?self
    {
        if ($user === null || ! method_exists($user, 'organizations')) {
            return null;
        }

        $ids = $user->organizations()->pluck('organizations.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $ids === [] ? null : new self($ids);
    }

    /**
     * Выполнить чтение в обход читающего скоупа моделей класса A.
     *
     * Обход — греппаемый (`withoutScope`), и заменой служит явный
     * `whereIn('organization_id', …)`, который обязан быть ВНУТРИ коллбэка.
     *
     * @template T
     *
     * @param  callable(list<int>): T  $query
     * @return T
     */
    public function run(callable $query): mixed
    {
        return app(OrganizationContext::class)
            ->withoutScope(fn (): mixed => $query($this->organizationIds));
    }
}
