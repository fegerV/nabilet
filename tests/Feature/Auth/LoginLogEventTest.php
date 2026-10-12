<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Nabilet\Modules\Auth\Domain\LoginLogEvent;
use Nabilet\Modules\Core\Models\LoginLog;
use Tests\TestCase;

/**
 * `login_logs.event` — вход, выход и отказ различимы по схеме.
 *
 * ДЕФЕКТ. Таблица писалась ровно из одного места —
 * `SessionIssuer::auditLogout()`, — то есть выходом. Строку входа от строки
 * выхода можно было отличить только косвенно: у входа `failure_code` заполнен,
 * у выхода — NULL. Это верно ровно до первого писателя, который начнёт писать
 * успешный вход с `failure_code = NULL`; после этого два события неразличимы
 * вовсе. §3.25 фиксировала: «у таблицы нет колонки события».
 *
 * ВТОРАЯ ПОЛОВИНА ДЕФЕКТА. Успешный вход не аудировался вообще: аудировался
 * только выход. Подбор пароля не оставлял следа — `failure_code` существовал
 * в схеме, но никто его не писал.
 *
 * Здесь проверяется и запись событий, и то, что словарь заперт CHECK — база
 * отвергает четвёртое событие.
 */
class LoginLogEventTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG = 'Kv7#mRta2!Qz';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::clear();
    }

    private function registerBuyer(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'buyer@example.com',
            'password' => self::STRONG,
            'password_confirmation' => self::STRONG,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ])->assertStatus(201);
    }

    private function login(array $overrides = [])
    {
        return $this->postJson('/api/v1/auth/login', array_merge([
            'email' => 'buyer@example.com',
            'password' => self::STRONG,
        ], $overrides));
    }

    public function test_a_successful_login_is_audited_as_a_login_event(): void
    {
        $this->registerBuyer();

        $this->login()->assertStatus(200);

        $log = LoginLog::query()->latest('id')->firstOrFail();

        $this->assertSame(LoginLogEvent::LOGIN, $log->event);
        $this->assertTrue((bool) $log->success);
        $this->assertSame('buyer@example.com', $log->identifier);
        // IP пишется упакованным (VARBINARY(16)); проверяем, что он есть, —
        // иначе строка аудита не говорит, откуда пришёл вход.
        $this->assertNotNull($log->ip_address);
    }

    public function test_a_refused_login_is_audited_with_a_failure_code(): void
    {
        $this->registerBuyer();

        $this->login(['password' => 'Wrong-Password-9!x'])->assertStatus(401);

        $log = LoginLog::query()->latest('id')->firstOrFail();

        $this->assertSame(LoginLogEvent::LOGIN_FAILED, $log->event);
        $this->assertFalse((bool) $log->success);
        $this->assertSame('bad_credentials', $log->failure_code);
    }

    public function test_a_refusal_for_an_unknown_address_is_also_audited(): void
    {
        // Аккаунта нет вовсе — строка аудита в этом случае тем ценнее:
        // обязательно нужно запомнить, С ЧЬЕГО адреса прилетел подбор.
        $this->login(['email' => 'nobody@example.com'])->assertStatus(401);

        $log = LoginLog::query()->latest('id')->firstOrFail();

        $this->assertSame(LoginLogEvent::LOGIN_FAILED, $log->event);
        $this->assertSame('nobody@example.com', $log->identifier);
        $this->assertNull($log->user_id);
    }

    public function test_logout_is_a_separate_event_from_login(): void
    {
        $this->registerBuyer();
        $token = $this->login()->assertStatus(200)->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertStatus(204);

        $logout = LoginLog::query()->latest('id')->firstOrFail();

        $this->assertSame(LoginLogEvent::LOGOUT, $logout->event);
        $this->assertTrue((bool) $logout->success);

        // Вход и выход — разные события, а не одна строка без разбора.
        $events = LoginLog::query()->pluck('event')->all();
        $this->assertContains(LoginLogEvent::LOGIN, $events);
        $this->assertContains(LoginLogEvent::LOGOUT, $events);
    }

    public function test_the_database_rejects_an_event_outside_the_vocabulary(): void
    {
        // CHECK из `016_login_logs_event.sql` — та же гарантия, что и у
        // `users/inventory/events.status`. Ожидаем QueryException именно
        // потому, что значение отвергает база, а не код приложения.
        $this->expectException(\Illuminate\Database\QueryException::class);

        LoginLog::query()->create([
            'user_id' => null,
            'identifier' => 'someone@example.com',
            'success' => true,
            'event' => 'session_hijack',
            'failure_code' => null,
            'created_at' => now(),
        ]);
    }
}
