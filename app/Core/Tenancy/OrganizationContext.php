<?php

declare(strict_types=1);

namespace Nabilet\Core\Tenancy;

use Nabilet\Core\Errors\TenantContextMissingError;

/**
 * Holds the organization that the current request/command operates on.
 *
 * MULTI-TENANCY RULE (ТЗ §10): one installation may serve several organizers.
 * Every tenant-scoped row carries `organization_id`, and every tenant-scoped
 * query MUST be filtered by it.
 *
 * The dangerous default is to filter only when an organization happens to be set.
 * Then a missing context silently widens a query to *all* organizations — and the
 * bug shows up as one client seeing another client's orders. So this class is
 * fail-closed:
 *
 *   - `id()` throws TenantContextMissingError when nothing is set;
 *   - the only way past it is `withoutScope()`, an explicit, greppable,
 *     reviewable opt-in used by the installer, migrations and scheduled jobs.
 *
 * @see docs/ARCHITECTURE.md — "Multi-tenancy"
 */
final class OrganizationContext
{
    private ?string $organizationId = null;

    private int $bypassDepth = 0;

    /** @var list<callable(?string): void> */
    private array $listeners = [];

    public function set(?string $organizationId): void
    {
        $previous = $this->organizationId;
        $this->organizationId = $organizationId;

        if ($previous !== $organizationId) {
            foreach ($this->listeners as $listener) {
                $listener($organizationId);
            }
        }
    }

    public function clear(): void
    {
        $this->set(null);
    }

    public function isSet(): bool
    {
        return $this->organizationId !== null;
    }

    public function tryId(): ?string
    {
        return $this->organizationId;
    }

    /**
     * Организация запроса как int, или null если контекста нет.
     *
     * ЗАЧЕМ. Преобразование `?string → ?int` стояло четырьмя одинаковыми
     * копиями в контроллерах чтения:
     *
     *   - `Events\Http\Controllers\EventGalleryController::organizationId()`
     *   - `Media\Http\Controllers\MediaController::organizationId()`
     *   - `Tickets\Http\Controllers\TicketTemplateController::organizationId()`
     *   - первая ветка `Pricing\Http\Controllers\PromoCodeController::organizationIdFor()`
     *
     * Все четыре отвечают на один вопрос: «есть ли у запроса арендатор, и какой
     * именно номер». Ответ null у них значит НЕ ошибку, а «список пуст»: без
     * организации галерея события, библиотека файлов и шаблоны билетов обязаны
     * вернуть пусто, а не всё подряд. Угаданная организация показала бы данные
     * чужого тенанта.
     *
     * ПОЧЕМУ `ctype_digit`, А НЕ ПРОСТО `(int)`. Внутри `withoutScope()`
     * `id()` возвращает ПУСТУЮ СТРОКУ — это явный системный контекст
     * (установщик, миграции, задания). `(int) ''` даёт 0, то есть организацию
     * с номером ноль: запрос ушёл бы с `organization_id = 0`, не нашёл ничего
     * и выглядел бы как «данных нет» вместо «контекста нет». `ctype_digit('')`
     * ложно, поэтому здесь null.
     *
     * Отказ (`id()`, исключение) — для путей записи. Этот метод намеренно
     * мягкий: он про чтение.
     */
    public function idOrNull(): ?int
    {
        $id = $this->organizationId;

        return $id !== null && ctype_digit($id) ? (int) $id : null;
    }

    /**
     * @throws TenantContextMissingError when no organization is active and we are
     *                                   not inside a withoutScope() block.
     */
    public function id(string $model = ''): string
    {
        if ($this->organizationId !== null) {
            return $this->organizationId;
        }

        if ($this->bypassDepth > 0) {
            // Inside an explicit system context: callers must handle a null
            // organization themselves (installer, global settings, etc.).
            return '';
        }

        throw new TenantContextMissingError($model);
    }

    public function isBypassing(): bool
    {
        return $this->bypassDepth > 0;
    }

    /**
     * Run a callback with tenant scoping explicitly disabled.
     *
     * Use only for: the installer, migrations, cross-tenant admin/reporting jobs,
     * and system-level maintenance. Nesting is supported and restores the previous
     * depth, so an exception cannot leave the bypass permanently open.
     */
    public function withoutScope(callable $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    /**
     * Assert that the given organization id matches the active one.
     * Used at the edge of every write to stop a caller from smuggling a foreign
     * organization_id into a payload.
     */
    public function assertOwns(?string $organizationId, string $resource = 'resource'): void
    {
        if ($this->bypassDepth > 0) {
            return;
        }

        if ($organizationId !== $this->id()) {
            throw new \Nabilet\Core\Errors\NotFoundError(ucfirst($resource), (string) $organizationId);
        }
    }

    /** @param callable(?string): void $listener */
    public function onChange(callable $listener): void
    {
        $this->listeners[] = $listener;
    }
}
