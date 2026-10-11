<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Политика паролей (аудит S-09).
 *
 * ДЕФЕКТ. Правила пароля были разными в трёх местах и ни в одном не проверяли
 * состав:
 *
 *   - `RegisterRequest`          — `min:12` + `confirmed`;
 *   - `UserController::store()`  — `min:8`;
 *   - `UserController::update()` — `min:8`.
 *
 * То есть покупатель регистрировался длинным паролем, а учётную запись
 * администратора — самую ценную в системе — можно было создать с `qwertyui1`.
 * Именно так и расходятся копии одного правила: «неудобную» ослабляют первой.
 *
 * ЧТО ПРОВЕРЯЕТСЯ ЗДЕСЬ. Не наличие класса `PasswordRules`, а наблюдаемое
 * поведение всех трёх точек: слабый пароль отвергается, пароль из личных данных
 * отвергается, сильный проходит, а пустое поле при обновлении означает «не
 * менять пароль», а не «поставить пустой».
 *
 * ПРОВЕРКА УТЕЧЕК ВЫКЛЮЧЕНА ПО УМОЛЧАНИЮ. `uncompromised()` — это внешний
 * сетевой вызов на каждую валидацию; включённый по умолчанию он поставил бы
 * регистрацию в зависимость от чужого сервиса. Тест это фиксирует через
 * `Http::preventStrayRequests()`: любой незапланированный запрос — ошибка.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** Пароль, который проходит политику: 12+, разный регистр, цифра, символ. */
    private const STRONG = 'Kv7#mRta2!Qz';

    public function test_a_short_password_is_refused_at_registration(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'buyer@example.com',
            'password' => 'qwertyui1',
            'password_confirmation' => 'qwertyui1',
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ])->assertStatus(422);

        $this->assertFalse(DB::table('users')->where('email', 'buyer@example.com')->exists());
    }

    /**
     * Двенадцать символов без состава — всё ещё слабый пароль.
     *
     * Это ровно то, что пропускала прежняя политика регистрации (`min:12`):
     * длина есть, энтропии нет.
     */
    public function test_a_long_but_simple_password_is_refused(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'buyer@example.com',
            'password' => 'qwertyuiopas',
            'password_confirmation' => 'qwertyuiopas',
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ])->assertStatus(422);
    }

    /**
     * Пароль из личных данных отвергается, даже если он «сложный».
     *
     * `Ivanov-2026!` проходит любую политику состава, но для атакующего это
     * лучший случай: цель известна заранее (e-mail виден в форме входа), а
     * словарь строится из имени и домена, а не из топ-10k утечек.
     */
    public function test_a_password_derived_from_personal_data_is_refused(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'ivanov@example.com',
            'password' => 'Ivanov-2026!',
            'password_confirmation' => 'Ivanov-2026!',
            'first_name' => 'Сергей',
            'last_name' => 'Иванов',
        ])->assertStatus(422);

        // Тот же пароль, но без связи с данными пользователя, — принимается.
        $this->postJson('/api/v1/auth/register', [
            'email' => 'petrova@example.com',
            'password' => 'Ivanov-2026!',
            'password_confirmation' => 'Ivanov-2026!',
            'first_name' => 'Анна',
            'last_name' => 'Петрова',
        ])->assertStatus(201);
    }

    public function test_a_strong_password_is_accepted_and_hashed(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/api/v1/auth/register', [
            'email' => 'buyer@example.com',
            'password' => self::STRONG,
            'password_confirmation' => self::STRONG,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ])->assertStatus(201);

        $hash = (string) DB::table('users')->where('email', 'buyer@example.com')->value('password');

        $this->assertNotSame(self::STRONG, $hash, 'пароль не должен храниться в открытом виде');
        $this->assertTrue(Hash::check(self::STRONG, $hash));
    }

    /**
     * Проверка утечек по умолчанию выключена — и это условие работы без сети.
     *
     * Флаг задокументирован в `config/nabilet.php` как вопрос деплоя:
     * `NABILET_PASSWORD_BREACH_CHECK=true` включает `uncompromised()`. Значение по
     * умолчанию обязано оставаться `false`, иначе регистрация зависит от
     * доступности HaveIBeenPwned, а тесты — от интернета.
     */
    public function test_the_breach_check_is_off_by_default(): void
    {
        $this->assertFalse(
            (bool) config('nabilet.auth.password.check_breaches'),
            'uncompromised() не должен быть включён по умолчанию: это сетевой вызов внутри валидации'
        );
    }

    /** Админское создание пользователя подчиняется ТОЙ ЖЕ политике. */
    public function test_admin_user_creation_uses_the_same_policy(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/users', [
            'email' => 'weak-admin@example.com',
            'password' => 'qwertyui1',
        ])->assertStatus(422);

        $this->postJson('/api/v1/users', [
            'email' => 'strong-admin@example.com',
            'password' => self::STRONG,
        ])->assertStatus(201);

        $this->assertFalse(DB::table('users')->where('email', 'weak-admin@example.com')->exists());
        $this->assertTrue(DB::table('users')->where('email', 'strong-admin@example.com')->exists());
    }

    /**
     * Смена пароля администратором — та же политика, а не «уж кто-кто, а админ
     * знает, что делает».
     */
    public function test_admin_password_change_uses_the_same_policy(): void
    {
        $this->actingAsAdmin();
        $target = $this->plainUser('target@example.com');
        $before = (string) $target->password;

        $this->putJson('/api/v1/users/' . $target->public_id, [
            'password' => 'qwertyui1',
        ])->assertStatus(422);

        $this->putJson('/api/v1/users/' . $target->public_id, [
            'password' => self::STRONG,
        ])->assertStatus(200);

        $after = (string) DB::table('users')->where('id', $target->id)->value('password');

        $this->assertNotSame($before, $after);
        $this->assertTrue(Hash::check(self::STRONG, $after));
    }

    /**
     * Пустое поле пароля при обновлении = «не менять», а не «поставить пустой».
     *
     * ЭТОТ ТЕСТ УЖЕ НАШЁЛ ДЕФЕКТ. Форма админки всегда отправляет `password`,
     * даже когда его не трогали, а правило было `['sometimes','nullable',…]`:
     * `nullable` пропускал `null` через остальные проверки, но оставлял ключ в
     * `validated()`. Дальше `$user->update(['password' => null])` записывал в
     * NOT NULL колонку `users.password` ПУСТУЮ строку — администратор, правивший
     * имя пользователя, стирал его пароль. Теперь пустое значение убирается из
     * данных (`Rule::excludeIf`), а `UserService`/`UserRepository` не пишут
     * пустой пароль даже в обход HTTP-валидации.
     *
     * Пустая строка и `null` обрабатываются одинаково: для формы это одно и то
     * же «поле не заполнено», и требовать от клиента различать их нельзя.
     */
    public function test_an_empty_password_on_update_does_not_change_the_password(): void
    {
        $this->actingAsAdmin();
        $target = $this->plainUser('target@example.com');
        $before = (string) $target->password;

        $this->putJson('/api/v1/users/' . $target->public_id, [
            'password' => null,
            'first_name' => 'Новое',
        ])->assertStatus(200);

        $this->assertSame($before, (string) DB::table('users')->where('id', $target->id)->value('password'));
        $this->assertSame('Новое', (string) DB::table('users')->where('id', $target->id)->value('first_name'));

        $this->putJson('/api/v1/users/' . $target->public_id, [
            'password' => '',
            'last_name' => 'Тоже новое',
        ])->assertStatus(200);

        $this->assertSame($before, (string) DB::table('users')->where('id', $target->id)->value('password'));
        $this->assertSame('Тоже новое', (string) DB::table('users')->where('id', $target->id)->value('last_name'));
    }

    /**
     * Слой записи не пропускает пустой пароль и в обход HTTP.
     *
     * `UserService::updateUser()` вызывают сидеры, скрипты и тесты — не только
     * контроллер. Проверка обязана стоять там, где значение превращается в
     * UPDATE, а не только в правилах запроса.
     */
    public function test_the_service_refuses_to_write_an_empty_password(): void
    {
        $target = $this->plainUser('target@example.com');
        $before = (string) $target->password;

        $this->app->make(\Nabilet\Modules\Core\Users\Services\UserService::class)
            ->updateUser($target, ['password' => null, 'first_name' => 'Через сервис']);

        $this->assertSame($before, (string) DB::table('users')->where('id', $target->id)->value('password'));
        $this->assertSame('Через сервис', (string) DB::table('users')->where('id', $target->id)->value('first_name'));
    }

    private function plainUser(string $email): User
    {
        $now = now();

        $id = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'password' => bcrypt(self::STRONG),
            'first_name' => 'Цель',
            'last_name' => 'Тестовая',
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::query()->findOrFail($id);
    }

    private function actingAsAdmin(): User
    {
        $now = now();

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Тестовая организация',
            'slug' => 'test-org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'admin@example.com',
            'password' => bcrypt(self::STRONG),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = DB::table('roles')->where('slug', 'admin')->value('id');

        if ($roleId === null) {
            $roleId = (int) DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
            ]);
        }

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        $user = User::query()->findOrFail($userId);
        $this->actingAs($user, 'api');

        return $user;
    }
}
