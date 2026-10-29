<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\TenantContextMissingError;
use Nabilet\Core\Tenancy\OrganizationContext;

/**
 * Трейт тенант-скоупа для моделей класса A (фаза 2).
 *
 * Два эффекта:
 *
 *   1. ЗАПИСЬ. При `creating` заполняет `organization_id` из активного контекста
 *      запроса, если колонка не задана. Если задана явно и расходится с
 *      контекстом — `NotFoundError` (неотличимо от несуществующего ресурса).
 *      Колонка НЕ в `$fillable`: значение приходит только из контекста, а не из
 *      тела запроса.
 *
 *   2. ЧТЕНИЕ (опционально). Если модель включила глобальный скоуп
 *      (`protected static bool $tenantScopeEnabled = true;`), каждый запрос
 *      фильтруется по `organization_id` контекста. Fail-closed: без контекста и
 *      вне `OrganizationContext::withoutScope()` — `TenantContextMissingError`,
 *      а не «показать всё».
 *
 * Глобальный скоуп включается точечно, модель за моделью, после того как
 * проверены системные пути чтения (задания, вебхуки, sweepers) — они обязаны
 * работать внутри `withoutScope()`. По умолчанию скоуп выключен, запись
 * включена всегда.
 */
trait TenantScoped
{
    /** @var string Имя глобального скоупа (для removeGlobalScope). */
    protected static string $tenantScopeName = 'tenant';

    public static function bootTenantScoped(): void
    {
        if (static::tenantScopeEnabled()) {
            static::addGlobalScope(static::$tenantScopeName, static function (Builder $builder): void {
                static::scopeByTenant($builder);
            });
        }

        static::creating(function (Model $model): void {
            static::fillTenantOnCreate($model);
        });
    }

    /**
     * Включить глобальный читающий скоуп (по умолчанию выключен, пока не проведён
     * аудит системных путей чтения). Модель может переопределить → true.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return false;
    }

    /**
     * Фильтрация запроса по активной организации.
     */
    protected static function scopeByTenant(Builder $builder): void
    {
        $context = app(OrganizationContext::class);

        if ($context->isBypassing()) {
            return;
        }

        if (! $context->isSet()) {
            throw new TenantContextMissingError(static::class);
        }

        $builder->where(static::tenantColumn(), $context->id());
    }

    /**
     * Заполнение `organization_id` при создании.
     */
    protected static function fillTenantOnCreate(Model $model): void
    {
        $column = static::tenantColumn();
        $given = $model->getAttribute($column);

        $context = app(OrganizationContext::class);

        if ($given !== null) {
            if (! $context->isBypassing() && $context->isSet() && (string) $given !== $context->tryId()) {
                throw new NotFoundError(ucfirst(class_basename(static::class)), $column);
            }

            return;
        }

        if ($context->isBypassing() || ! $context->isSet()) {
            // Нет контекста — не угадываем организацию. Для обязательных колонок
            // дальше сработает NOT NULL (фаза 3); для системных созданий это
            // намеренно допустимо.
            return;
        }

        $model->setAttribute($column, $context->id());
    }

    protected static function tenantColumn(): string
    {
        return 'organization_id';
    }
}
