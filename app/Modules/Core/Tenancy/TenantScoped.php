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
 *      запроса, если колонка не задана. Если контекста нет (вебхук, гостевой
 *      checkout, консоль), организация выводится из РОДИТЕЛЯ через
 *      `TenantResolver` — билет из заказа, заказ из сеанса и т.д. Если задана
 *      явно и расходится с контекстом или родителем — `NotFoundError`
 *      (неотличимо от несуществующего ресурса). Колонка НЕ в `$fillable`:
 *      значение приходит только из контекста/родителя, а не из тела запроса.
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

        // Организация родителя: единственный источник истины там, где контекста
        // запроса нет (вебхук, гостевой checkout, консоль). Считается ДО ветки
        // явного значения, чтобы поймать попытку записать строку чужому тенанту.
        $fromParent = TenantResolver::fromAttributes($model->getTable(), $model->getAttributes());

        if ($given !== null) {
            $tenant = (string) $given;

            if (! $context->isBypassing() && $context->isSet() && $tenant !== $context->tryId()) {
                throw new NotFoundError(ucfirst(class_basename(static::class)), $column);
            }

            if ($fromParent !== null && $tenant !== (string) $fromParent) {
                throw new NotFoundError(ucfirst(class_basename(static::class)), $column);
            }

            return;
        }

        if ($context->isSet()) {
            if ($fromParent !== null && (string) $fromParent !== $context->tryId()) {
                // Строка одного арендатора не может ссылаться на родителя
                // другого: это тот же IDOR, только на записи.
                throw new NotFoundError(ucfirst(class_basename(static::class)), $column);
            }

            $model->setAttribute($column, $context->id());

            return;
        }

        if ($fromParent !== null) {
            $model->setAttribute($column, $fromParent);

            return;
        }

        // Ни контекста, ни родителя — не угадываем. Для обязательных колонок
        // дальше сработает NOT NULL; для системных таблиц это допустимо.
    }

    protected static function tenantColumn(): string
    {
        return 'organization_id';
    }
}
