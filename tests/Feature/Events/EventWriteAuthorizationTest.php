<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Nabilet\Modules\Core\Models\Role;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Events\Http\Requests\StoreEventRequest;
use Nabilet\Modules\Events\Http\Requests\UpdateEventRequest;
use Nabilet\Modules\Inventory\Http\Requests\UpdateRowPricesRequest;
use Tests\TestCase;

/**
 * Write-роуты мероприятий: «кто имеет право» спрашивают у одного владельца.
 *
 * `StoreEventRequest::authorize()`, `UpdateEventRequest::authorize()` и
 * `UpdateRowPricesRequest::authorize()` держали одну и ту же проверку роли
 * побайтово:
 *
 *     $user->roles()->pluck('slug')->intersect(['admin', 'manager'])->isNotEmpty()
 *
 * То есть список ролей админ-API существовал в ТРЁХ FormRequest'ах плюс
 * `EnsureAdminRole` и `StaffRole::ROLES` — пять копий. Расхождение любой из
 * них с остальными незаметно: middleware пропускает, а FormRequest отвечает
 * 403 (или наоборот), и снаружи это выглядит как «доступ то есть, то нет».
 *
 * Теперь все три спрашивают `StaffRole::isStaff()`. Здесь проверяется, что
 * rewiring не изменил ОТВЕТ ни на одном конце: без роли — отказ, с `manager`
 * — пропуск, с `admin` — пропуск.
 *
 * Без БД: `authorize()` — единственный метод, который тут вызывается, и он
 * работает с пользователем, у которого relation `roles` уже загружен.
 */
final class EventWriteAuthorizationTest extends TestCase
{
    public function test_a_user_without_a_role_is_refused_by_every_write_request(): void
    {
        $user = $this->userWithRoles([]);

        $this->assertFalse($this->authorizeAs(StoreEventRequest::class, 'POST', $user));
        $this->assertFalse($this->authorizeAs(UpdateEventRequest::class, 'PUT', $user));
        $this->assertFalse($this->authorizeAs(UpdateRowPricesRequest::class, 'PATCH', $user));
    }

    public function test_a_manager_is_staff_for_every_write_request(): void
    {
        $user = $this->userWithRoles(['manager']);

        $this->assertTrue($this->authorizeAs(StoreEventRequest::class, 'POST', $user));
        $this->assertTrue($this->authorizeAs(UpdateEventRequest::class, 'PUT', $user));
        $this->assertTrue($this->authorizeAs(UpdateRowPricesRequest::class, 'PATCH', $user));
    }

    public function test_an_admin_is_staff_for_every_write_request(): void
    {
        $user = $this->userWithRoles(['admin']);

        $this->assertTrue($this->authorizeAs(StoreEventRequest::class, 'POST', $user));
        $this->assertTrue($this->authorizeAs(UpdateEventRequest::class, 'PUT', $user));
        $this->assertTrue($this->authorizeAs(UpdateRowPricesRequest::class, 'PATCH', $user));
    }

    public function test_a_guest_is_refused_by_every_write_request(): void
    {
        $this->assertFalse($this->authorizeAs(StoreEventRequest::class, 'POST', null));
        $this->assertFalse($this->authorizeAs(UpdateEventRequest::class, 'PUT', null));
        $this->assertFalse($this->authorizeAs(UpdateRowPricesRequest::class, 'PATCH', null));
    }

    /**
     * @param  class-string<StoreEventRequest|UpdateEventRequest|UpdateRowPricesRequest>  $class
     */
    private function authorizeAs(string $class, string $method, ?User $user): bool
    {
        $request = $class::create('/api/v1/events', $method);
        $request->setUserResolver(fn () => $user);

        return $request->authorize();
    }

    /**
     * Пользователь с загруженной relation `roles` — ровно то, что читает
     * `StaffRole::isStaff()`. Без запроса к БД.
     *
     * @param  list<string>  $slugs
     */
    private function userWithRoles(array $slugs): User
    {
        $user = new User();
        $user->setRelation('roles', collect($slugs)->map(fn (string $slug) => new Role(['slug' => $slug])));

        return $user;
    }
}
