<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Конверт §66 на неудачном входе.
 *
 * ДЕФЕКТ. `AuthController::login()` отвечал плоской строкой
 * `{"error": "Invalid credentials"}` — это было последнее исключение из
 * контракта §66 и последняя запись ратчета `tools/verify-error-envelope.php`
 * (снята вместе с этой правкой). `docs/openapi.yaml` на 401 у `/auth/login`
 * ссылается на `ErrorResponse`, то есть на конверт, а не на строку.
 *
 * ПОЧЕМУ ЭТО НЕ КОСМЕТИКА. Код `bad_credentials` читает клиент:
 * `resources/js/lib/api.ts` на ЛЮБОЙ 401 стирает сохранённый токен, кроме
 * этого кода. Пока сервер не отдавал код вообще, исключение было недостижимым,
 * и неудачная попытка входа выкидывала уже открытую сессию.
 *
 * Проверяется наблюдаемое тело ответа, а не наличие класса `AuthError`:
 * именно форма тела — то, на что ветвится клиент.
 */
class LoginErrorEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    /** Пароль, проходящий политику: 12+, разный регистр, цифра, символ. */
    private const STRONG = 'Kv7#mRta2!Qz';

    protected function setUp(): void
    {
        parent::setUp();

        // `throttle:auth` считает попытки в кэше, а кэш между тестами не
        // очищается: счётчик одного теста блокирует следующий и превращает его
        // в 429. Сбрасываем целиком — дешевле, чем перечислять ключи лимитера.
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

    public function test_unknown_account_returns_the_envelope_with_bad_credentials_code(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'Wrong-Password-9!x',
        ]);

        $response->assertStatus(401);

        // `error` обязан быть ОБЪЕКТОМ: по нему ветвится клиент, плоскую строку
        // разобрать нечем.
        $this->assertIsArray($response->json('error'), 'error должен быть объектом, а не строкой');

        $response->assertJsonStructure(['error' => ['code', 'message']]);
        $response->assertJsonPath('error.code', 'bad_credentials');
    }

    public function test_wrong_password_for_an_existing_account_returns_the_same_envelope(): void
    {
        $this->registerBuyer();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@example.com',
            'password' => 'Wrong-Password-9!x',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'bad_credentials');
    }

    public function test_the_envelope_does_not_reveal_whether_the_account_exists(): void
    {
        $this->registerBuyer();

        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'Wrong-Password-9!x',
        ]);
        $existing = $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@example.com',
            'password' => 'Wrong-Password-9!x',
        ]);

        // Разные ответы превратили бы вход в оракул перечисления аккаунтов.
        $this->assertSame($unknown->json('error.code'), $existing->json('error.code'));
        $this->assertSame($unknown->json('error.message'), $existing->json('error.message'));
    }

    public function test_a_successful_login_still_returns_a_token(): void
    {
        $this->registerBuyer();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@example.com',
            'password' => self::STRONG,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['user', 'token', 'token_type']]);
    }
}
