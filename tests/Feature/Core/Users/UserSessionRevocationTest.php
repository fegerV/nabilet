<?php

declare(strict_types=1);

namespace Tests\Feature\Core\Users;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Отзыв сессий при административных изменениях учётной записи (аудит S-03).
 *
 * Токен — самостоятельная запись в `user_sessions`, и он не перестаёт работать
 * от смены пароля: `SessionTokenGuard::user()` ищет сессию по хэшу токена и
 * намеренно НЕ проверяет `users.status`. Поэтому «погасить инцидент» сменой
 * пароля или баном без явного удаления сессий означало закрыть дверь, оставив
 * того, кто внутри, — ровно то, от чего защищаются.
 *
 * Здесь проверяется сквозной путь: логин → рабочий токен → действие админа →
 * тот же токен обязан отвечать 401.
 */
class UserSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_a_password_revokes_the_existing_tokens(): void
    {
        $victim = $this->staffUser('victim@example.com', 'original-password');
        $victimToken = $this->login('victim@example.com', 'original-password');
        $adminToken = $this->adminToken();

        $this->getJson('/api/v1/users', $this->asToken($victimToken))->assertOk();

        $this->putJson(
            '/api/v1/users/' . $victim->public_id,
            ['password' => 'Brand-New-Pass9!x'],
            $this->asToken($adminToken),
        )->assertOk();

        $this->assertSame(
            0,
            DB::table('user_sessions')->where('user_id', $victim->id)->count(),
            'Смена пароля обязана удалить активные сессии пользователя'
        );

        $this->getJson('/api/v1/users', $this->asToken($victimToken))->assertUnauthorized();
    }

    public function test_banning_a_user_revokes_the_existing_tokens(): void
    {
        $victim = $this->staffUser('victim@example.com', 'original-password');
        $victimToken = $this->login('victim@example.com', 'original-password');
        $adminToken = $this->adminToken();

        $this->getJson('/api/v1/users', $this->asToken($victimToken))->assertOk();

        $this->putJson(
            '/api/v1/users/' . $victim->public_id,
            ['status' => 'banned'],
            $this->asToken($adminToken),
        )->assertOk();

        $this->getJson('/api/v1/users', $this->asToken($victimToken))->assertUnauthorized();
    }

    /**
     * Обратная сторона: правка, не связанная с доступом, не должна выкидывать
     * пользователя из системы. Иначе фикс S-03 превращается в «любой PUT
     * разлогинивает», и оператор перестаёт пользоваться админкой.
     */
    public function test_an_unrelated_update_keeps_the_tokens_alive(): void
    {
        $victim = $this->staffUser('victim@example.com', 'original-password');
        $victimToken = $this->login('victim@example.com', 'original-password');
        $adminToken = $this->adminToken();

        $this->putJson(
            '/api/v1/users/' . $victim->public_id,
            ['first_name' => 'Переименованный'],
            $this->asToken($adminToken),
        )->assertOk();

        $this->getJson('/api/v1/users', $this->asToken($victimToken))->assertOk();
    }

    /**
     * Пустой пароль — тоже «не смена пароля», и он не должен обрывать сессии.
     *
     * Форма админки отправляет поле `password` ВСЕГДА, и при нетронутом input
     * это пустая строка. Пока ключ оставался в `validated()` (правило
     * `nullable`), `array_key_exists('password', $data)` было истинным, и
     * `UserService::updateUser()` отзывал все токены пользователя: правка имени
     * выкидывала человека из системы на всех устройствах. Заодно стирался сам
     * пароль — см. `PasswordPolicyTest`.
     */
    public function test_an_empty_password_does_not_revoke_the_tokens(): void
    {
        $victim = $this->staffUser('victim@example.com', 'original-password');
        $victimToken = $this->login('victim@example.com', 'original-password');
        $adminToken = $this->adminToken();

        $this->putJson(
            '/api/v1/users/' . $victim->public_id,
            ['password' => '', 'first_name' => 'Переименованный'],
            $this->asToken($adminToken),
        )->assertOk();

        $this->getJson('/api/v1/users', $this->asToken($victimToken))->assertOk();
    }

    /**
     * Деактивация — тоже лишение доступа, а не «мягкий» флаг: аудит S-03 просит
     * отзывать токены и при `banned`, и при `inactive`. Иначе «отключённый»
     * пользователь продолжает работать до истечения токена (30 дней).
     */
    public function test_deactivating_a_user_revokes_the_existing_tokens(): void
    {
        $victim = $this->staffUser('victim@example.com', 'original-password');
        $victimToken = $this->login('victim@example.com', 'original-password');
        $adminToken = $this->adminToken();

        $this->getJson('/api/v1/users', $this->asToken($victimToken))->assertOk();

        $this->putJson(
            '/api/v1/users/' . $victim->public_id,
            ['status' => 'inactive'],
            $this->asToken($adminToken),
        )->assertOk();

        $this->getJson('/api/v1/users', $this->asToken($victimToken))->assertUnauthorized();
    }

    /**
     * Запись `status=active` в уже активную учётную запись — не изменение
     * доступа: отзывать нечего, и выкидывать пользователя из системы нельзя.
     */
    public function test_rewriting_the_active_status_does_not_revoke_the_tokens(): void
    {
        $victim = $this->staffUser('victim@example.com', 'original-password');
        $victimToken = $this->login('victim@example.com', 'original-password');
        $adminToken = $this->adminToken();

        $this->putJson(
            '/api/v1/users/' . $victim->public_id,
            ['status' => 'active'],
            $this->asToken($adminToken),
        )->assertOk();

        $this->getJson('/api/v1/users', $this->asToken($victimToken))->assertOk();
    }

    /* ── фикстуры ─────────────────────────────────────────────────────────── */

    /** @return array<string, string> */
    private function asToken(string $token): array
    {
        // `forgetGuards()` обязателен: `SessionTokenGuard` кеширует результат
        // `user()` (включая отрицательный) на весь жизненный цикл экземпляра, а
        // экземпляр живёт до конца теста — без сброса второй запрос вернул бы
        // ответ первого и «отзыв» остался бы невидимым.
        $this->app['auth']->forgetGuards();

        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    private function login(string $email, string $password): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ])->assertOk();

        return (string) $response->json('data.token');
    }

    private function adminToken(): string
    {
        $this->staffUser('admin@example.com', 'admin-password');

        return $this->login('admin@example.com', 'admin-password');
    }

    /**
     * Пользователь с ролью admin: `StaffRole::isStaff()` читает `user_roles`,
     * а `User::organizations()` — `user_organization`, поэтому пишем обе.
     */
    private function staffUser(string $email, string $password): User
    {
        $now = now();

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Org ' . $email,
            'slug' => 'org-' . md5($email),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'password' => bcrypt($password),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'email_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        return User::query()->findOrFail($userId);
    }
}
