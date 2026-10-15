<?php

declare(strict_types=1);

namespace Tests\Feature\Core\Users;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Статус учётной записи и вход.
 *
 * ДЕФЕКТ. `users.status` был «мягким флагом»: `UserController` принимал
 * `active|inactive|banned`, `UserService::updateUser()` при не-`active` отзывал
 * все сессии пользователя — и на этом всё заканчивалось. `authenticate()`
 * сверял только хеш пароля, поэтому забаненный человек входил тем же паролем
 * сразу после «бана» и получал свежий токен. Отзыв сессий без отказа во входе —
 * это выключение до следующего логина, а не лишение доступа.
 *
 * Второе следствие: словарь существовал ровно в одном месте — строкой в правиле
 * валидации контроллера. Любая запись мимо HTTP (сидер, скрипт, прямой SQL)
 * кладёт в колонку что угодно. Теперь это невозможно: `ck_users_status`
 * (сплит `013_users_status_check.sql`) отвергает значение вне словаря, а
 * `authenticate()` трактует любое не-`active` как «вход запрещён» (fail-closed) —
 * второе нужно для строк, записанных до появления ограничения.
 *
 * Словарь сведён в `Nabilet\Modules\Core\Users\Domain\UserStatus` — одно
 * определение на правило валидации, на CHECK в схеме и на проверку при входе.
 */
class UserStatusLoginTest extends TestCase
{
    use RefreshDatabase;

    /** Пароль, проходящий политику: 12+, разный регистр, цифра, символ. */
    private const STRONG = 'Kv7#mRta2!Qz';

    private const EMAIL = 'buyer@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        // `throttle:auth` считает попытки в кэше, который между тестами не
        // очищается: счётчик одного теста превращает следующий в 429.
        Cache::clear();
    }

    /** Зарегистрировать покупателя и вернуть его id. */
    private function registerBuyer(): int
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => self::EMAIL,
            'password' => self::STRONG,
            'password_confirmation' => self::STRONG,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ])->assertStatus(201);

        return (int) DB::table('users')->where('email', self::EMAIL)->value('id');
    }

    private function setStatus(int $id, string $status): void
    {
        DB::table('users')->where('id', $id)->update(['status' => $status]);
    }

    private function attemptLogin()
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => self::STRONG,
        ]);
    }

    public function test_an_active_account_can_log_in(): void
    {
        $id = $this->registerBuyer();
        $this->setStatus($id, 'active');

        $this->attemptLogin()->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_a_banned_account_cannot_log_in_even_with_the_correct_password(): void
    {
        $id = $this->registerBuyer();
        $this->setStatus($id, 'banned');

        // Регистрация сама выдаёт сессию, поэтому сравниваем не с нулём, а с
        // состоянием до попытки: отказ обязан не создать НОВУЮ сессию.
        $before = DB::table('user_sessions')->where('user_id', $id)->count();

        $this->attemptLogin()->assertStatus(401);

        $this->assertSame(
            $before,
            DB::table('user_sessions')->where('user_id', $id)->count(),
            'Отказ во входе не должен выдавать сессию'
        );
    }

    /**
     * `inactive` — тоже лишение доступа, а не «мягкий» флаг: так это уже
     * понимает отзыв сессий (`UserSessionRevocationTest`).
     */
    public function test_a_deactivated_account_cannot_log_in(): void
    {
        $id = $this->registerBuyer();
        $this->setStatus($id, 'inactive');

        $this->attemptLogin()->assertStatus(401);
    }

    /**
     * Неизвестное значение словаря до базы не доходит.
     *
     * Колонка объявлена `VARCHAR(32)`, и раньше «неизвестное» достигалось
     * обычной записью: сидер, скрипт, прямой SQL — база принимала молча.
     * Теперь `ck_users_status` отвергает такое значение сам, поэтому словарь
     * живёт не в одном правиле валидации, а в схеме.
     *
     * Ветка «неизвестное = вход запрещён» в `UserService::authenticate()`
     * при этом не удалена: она защищает строки, записанные до появления
     * ограничения. Её поведение проверяет `Tests\Unit\UserStatusTest`.
     */
    public function test_the_database_rejects_a_status_outside_the_dictionary(): void
    {
        $id = $this->registerBuyer();

        $this->expectException(QueryException::class);

        $this->setStatus($id, 'suspended'); // значения нет в словаре
    }

    public function test_unblocking_restores_access(): void
    {
        $id = $this->registerBuyer();

        $this->setStatus($id, 'banned');
        $this->attemptLogin()->assertStatus(401);

        $this->setStatus($id, 'active');
        $this->attemptLogin()->assertOk();
    }

    /**
     * Отказ по статусу неотличим от неверного пароля: иначе вход становится
     * оракулом состояния чужого аккаунта.
     */
    public function test_the_refusal_looks_like_a_wrong_password(): void
    {
        $id = $this->registerBuyer();
        $this->setStatus($id, 'banned');

        $blocked = $this->attemptLogin();
        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => 'Definitely-Not-It-9!x',
        ]);

        $this->assertSame(401, $blocked->status());
        $this->assertSame($wrongPassword->status(), $blocked->status());
        $this->assertSame($wrongPassword->json('error.code'), $blocked->json('error.code'));
        $this->assertSame($wrongPassword->json('error.message'), $blocked->json('error.message'));
    }
}
