<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Core\Users\Services\UserService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Разрешение права доступа: «есть ли у пользователя право X».
 *
 * ЗАЧЕМ ЭТОТ ТЕСТ. Права описаны в базе полностью: `permissions` (18 записей),
 * `role_permissions` (кому что выдано), `roles`. Сидер `NabiletAdminSeeder`
 * наполняет всё это, и по нему видно замысел: у `support` шесть прав
 * (`view_events`, `view_orders`, `view_payments`, `view_tickets`,
 * `checkin_tickets`, `view_users`), у `manager` десять, у `admin` все
 * восемнадцать.
 *
 * Проверять это было нечем: единственный существовавший тест прав
 * (`InstallerHardeningTest`) утверждал, что строк в `permissions` и
 * `role_permissions` больше нуля. Такой тест зелёный и тогда, когда разрешение
 * прав не работает вовсе, — что и произошло.
 *
 * ЧТО ИМЕННО БЫЛО СЛОМАНО. `UserRepository::hasPermission()` фильтровал
 * `where('name', $permission)`. Но `name` в `permissions` — ЧЕЛОВЕЧЕСКАЯ
 * ПОДПИСЬ («Просмотр мероприятий»), а машинный идентификатор лежит в `slug`
 * (`view_events`, он же UNIQUE). Вызывающие передают именно slug — так ключует
 * сидер, и так выглядит пример в докблоке `RequirePermission`
 * (`permission:orders.refund`). Значит сравнение шло между slug'ом и русской
 * подписью и не совпадало НИКОГДА: ответ всегда `false`, независимо от выданных
 * прав.
 *
 * ПОЧЕМУ ЭТО НЕ ПОЙМАЛ НИ ОДИН ГЕЙТ. Проверка прав не подключена ни к одному
 * маршруту (`permission:` в `routes/` не встречается ни разу), поэтому
 * «всегда false» не проявлялось как отказ в доступе — оно просто не
 * вызывалось. Дефект был латентным: первый же маршрут с `permission:` запретил
 * бы доступ всем, включая администратора, и выглядело бы это как «права
 * сломались», а не как «сравнивается не та колонка».
 *
 * ЧТО ЭТОТ ТЕСТ НЕ ДЕЛАЕТ. Он не подключает `RequirePermission` к маршрутам и
 * не расширяет `StaffRole`. Это разные вопросы: `StaffRole::isStaff()` отвечает
 * «сотрудник ли это» (грубо, по slug роли) и подключён; таблица прав отвечает
 * «что именно ему можно» и не подключена. Подключение — отдельное решение об
 * управлении доступом, а не исправление дефекта, поэтому оно не делается здесь
 * молча.
 */
final class PermissionResolutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_granted_permission_is_resolved_by_its_machine_name(): void
    {
        $organizationId = $this->createOrganization();
        $user = $this->createUserWithRole($organizationId, 'support');
        $this->grantPermissions('support', ['view_events' => 'Просмотр мероприятий']);

        $this->assertTrue(
            $this->service()->hasPermission($user, 'view_events'),
            'Право выдано роли, но проверка его не нашла: сравнивается не та колонка.'
        );
    }

    #[Test]
    public function a_permission_the_role_does_not_carry_is_refused(): void
    {
        $organizationId = $this->createOrganization();
        $user = $this->createUserWithRole($organizationId, 'support');
        $this->grantPermissions('support', ['view_events' => 'Просмотр мероприятий']);

        // `refund_payments` есть только у admin: возврат денег — не то право,
        // которое саппорт получает по умолчанию.
        $this->assertFalse($this->service()->hasPermission($user, 'refund_payments'));
    }

    #[Test]
    public function the_human_readable_label_is_not_an_identifier(): void
    {
        $organizationId = $this->createOrganization();
        $user = $this->createUserWithRole($organizationId, 'support');
        $this->grantPermissions('support', ['view_events' => 'Просмотр мероприятий']);

        // Это утверждение фиксирует НАПРАВЛЕНИЕ исправления: идентификатор —
        // slug. Если кто-то вернёт сравнение по `name`, тест выше упадёт, а
        // этот пройдёт — то есть пара тестов различает две колонки, а не
        // просто «право нашлось».
        $this->assertFalse(
            $this->service()->hasPermission($user, 'Просмотр мероприятий'),
            'Подпись не должна работать как идентификатор права.'
        );
    }

    #[Test]
    public function every_permission_granted_to_the_role_is_resolved(): void
    {
        $organizationId = $this->createOrganization();
        $user = $this->createUserWithRole($organizationId, 'admin');

        $granted = [
            'view_events' => 'Просмотр мероприятий',
            'refund_payments' => 'Возврат платежей',
            'manage_settings' => 'Управление настройками',
        ];

        $this->grantPermissions('admin', $granted);

        foreach (array_keys($granted) as $slug) {
            $this->assertTrue(
                $this->service()->hasPermission($user, $slug),
                sprintf('Право «%s» выдано роли admin, но не найдено.', $slug)
            );
        }
    }

    #[Test]
    public function a_user_without_roles_has_no_permissions(): void
    {
        $this->grantPermissions('support', ['view_events' => 'Просмотр мероприятий']);

        $user = User::query()->findOrFail($this->createUser());

        $this->assertFalse($this->service()->hasPermission($user, 'view_events'));
    }

    private function service(): UserService
    {
        return app(UserService::class);
    }

    /**
     * Выдать роли права так же, как это делает сидер: `permissions` с парой
     * (подпись, slug) и пара в `role_permissions`.
     *
     * @param  array<string, string>  $permissions  slug => подпись
     */
    private function grantPermissions(string $roleSlug, array $permissions): void
    {
        $roleId = $this->roleId($roleSlug);

        foreach ($permissions as $slug => $name) {
            $permissionId = (int) (DB::table('permissions')->where('slug', $slug)->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'slug' => $slug]));

            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    private function roleId(string $roleSlug): int
    {
        return (int) (DB::table('roles')->where('slug', $roleSlug)->value('id')
            ?? DB::table('roles')->insertGetId(['name' => ucfirst($roleSlug), 'slug' => $roleSlug]));
    }

    private function createUserWithRole(int $organizationId, string $roleSlug): User
    {
        $userId = $this->createUser();

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $this->roleId($roleSlug),
            'created_at' => now()->toDateTimeString(),
        ]);

        return User::query()->findOrFail($userId);
    }

    private function createUser(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'perm-' . Str::random(8) . '@example.test',
            'password' => Hash::make('Kv7#mRta2!Qz'),
            'first_name' => 'Права',
            'last_name' => 'Проверка',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createOrganization(): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Permission Test Org',
            'slug' => 'permission-test-' . Str::random(6),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
