<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Nabilet\Modules\Auth\Domain\AccountTokenPurpose;
use Nabilet\Modules\Auth\Services\AccountTokenService;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Core\Users\Repositories\UserRepository;
use Nabilet\Modules\Notifications\Mail\TemplateMail;
use Nabilet\Modules\Notifications\Support\AccountNotificationCodes;
use Tests\TestCase;

/**
 * Password recovery and e-mail verification.
 *
 * The tests are grouped by the rule they defend rather than by endpoint, because
 * two of these rules are the reason the endpoints exist at all:
 *
 *   - `requestPasswordReset()` must not reveal whether an address is registered,
 *     or the endpoint is an enumeration oracle.
 *   - A successful reset must end every session, or "I think someone is in my
 *     account" cannot be acted on by the one action a user can take.
 *
 * The rest cover the token itself: purpose binding, expiry, and the password
 * fingerprint that makes a link stop working once the password changes.
 */
class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Именованный лимитер `auth` живёт в кэше (`array` в тестах), а кэш не
        // очищается между тестами: счётчик одного теста блокирует следующий, и
        // все, кроме первого, получают 429. Сбрасываем кэш целиком — это дешевле
        // и надёжнее, чем перечислять ключи, которые `registerRateLimiters()`
        // строит из адреса и IP.
        app('cache')->flush();

        // Реальный SMTP в тестах не поднимается; письма проверяются через
        // Mail::fake(), а шаблоны должны существовать, иначе сервис молча
        // не отправляет (и тест поймает это как ложный успех).
        Mail::fake();
        $this->seedTemplates();
    }

    // ── forgot password ──────────────────────────────────────────────────────

    public function test_forgot_password_answers_204_for_a_known_address_and_dispatches_mail(): void
    {
        $user = $this->makeUser('buyer@example.test');

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'buyer@example.test'])
            ->assertNoContent();

        Mail::assertSent(TemplateMail::class, function (TemplateMail $mail) use ($user): bool {
            return str_contains($mail->subjectLine, 'Смена пароля')
                && str_contains($mail->htmlBody, 'token=')
                && str_contains($mail->htmlBody, 'buyer%40example.test');
        });

        $log = Notification::query()->where('type', AccountNotificationCodes::PASSWORD_RESET)->firstOrFail();
        self::assertSame('sent', $log->status);
        self::assertSame($user->id, $log->user_id);
    }

    public function test_forgot_password_answers_204_for_an_unknown_address_and_sends_nothing(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.test'])
            ->assertNoContent();

        Mail::assertNothingOutgoing();

        // Ключевое: ответ неотличим от случая с существующим адресом — тот же
        // статус, то же пустое тело. Иначе эндпоинт перечисляет базу адресов.
        self::assertSame(0, Notification::query()->count());
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        // `throttle:auth` = 5 в минуту; шестой запрос должен упереться в лимит.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/password/forgot', ['email' => 'buyer@example.test'])
                ->assertNoContent();
        }

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'buyer@example.test'])
            ->assertStatus(429);
    }

    // ── reset password ───────────────────────────────────────────────────────

    public function test_reset_password_changes_the_password_and_revokes_every_session(): void
    {
        $user = $this->makeUser('buyer@example.test', 'old-password-value');

        // Две живые сессии: «сменил пароль, но остался залогинен на ноутбуке».
        // Через `DB::table()`, а не модель: `created_at` у `user_sessions` —
        // NOT NULL без default и отсутствует в `$fillable`, поэтому `create()`
        // его молча отбрасывает и вставка падает на 1364.
        foreach (['session-a', 'session-b'] as $seed) {
            DB::table('user_sessions')->insert([
                'user_id' => $user->id,
                'session_token_hash' => hash('sha256', $seed),
                'created_at' => now(),
                'expires_at' => now()->addMonth(),
            ]);
        }

        $token = app(AccountTokenService::class)->issue($user, AccountTokenPurpose::PasswordReset);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'buyer@example.test',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertNoContent();

        // Обе старые сессии отозваны — это и есть «выйти везде». Проверяем
        // ДО нового логина: вход ниже сам создаёт сессию, и она не должна
        // считаться остатком старой.
        self::assertSame(0, DB::table('user_sessions')->where('user_id', $user->id)->count());

        // Новый пароль работает, старый — нет.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@example.test',
            'password' => 'a-brand-new-password',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@example.test',
            'password' => 'old-password-value',
        ])->assertStatus(401);
    }

    public function test_a_reset_token_cannot_be_used_twice(): void
    {
        $user = $this->makeUser('buyer@example.test', 'old-password-value');
        $token = app(AccountTokenService::class)->issue($user, AccountTokenPurpose::PasswordReset);

        $payload = [
            'token' => $token,
            'email' => 'buyer@example.test',
            'password' => 'first-new-password',
            'password_confirmation' => 'first-new-password',
        ];

        $this->postJson('/api/v1/auth/password/reset', $payload)->assertNoContent();

        // Тот же токен повторно: пароль уже другой, отпечаток в токене не
        // совпадает => ссылка недействительна. Одноразовость здесь не хранится в
        // БД, а следует из смены пароля — см. AccountTokenService.
        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'buyer@example.test',
            'password' => 'second-new-password',
            'password_confirmation' => 'second-new-password',
        ])->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_TOKEN_INVALID');

        // Пароль остался от первого сброса.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@example.test',
            'password' => 'first-new-password',
        ])->assertOk();
    }

    public function test_a_forged_token_is_rejected(): void
    {
        $this->makeUser('buyer@example.test');

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => 'not-a-real-token',
            'email' => 'buyer@example.test',
            'password' => 'whatever-password',
            'password_confirmation' => 'whatever-password',
        ])->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_TOKEN_INVALID');
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user = $this->makeUser('buyer@example.test');

        $token = app(AccountTokenService::class)->issue(
            $user,
            AccountTokenPurpose::PasswordReset,
            now()->subHour(),
            ttlSeconds: 60,
        );

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'buyer@example.test',
            'password' => 'whatever-password',
            'password_confirmation' => 'whatever-password',
        ])->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_TOKEN_INVALID');
    }

    public function test_a_reset_token_minted_for_another_address_is_rejected(): void
    {
        $user = $this->makeUser('buyer@example.test');

        $token = app(AccountTokenService::class)->issue($user, AccountTokenPurpose::PasswordReset);

        // Токен подписан на buyer@, но предъявлен с чужим адресом в теле.
        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'attacker@example.test',
            'password' => 'whatever-password',
            'password_confirmation' => 'whatever-password',
        ])->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_TOKEN_INVALID');
    }

    // ── purpose binding ──────────────────────────────────────────────────────

    public function test_a_verification_token_cannot_be_replayed_to_reset_a_password(): void
    {
        $user = $this->makeUser('buyer@example.test', 'old-password-value');

        // Токен выпущен для подтверждения адреса — им нельзя сменить пароль.
        $token = app(AccountTokenService::class)->issue($user, AccountTokenPurpose::EmailVerification);

        // Адрес уже подтверждён при регистрации, поэтому сбросим его, чтобы
        // проверка неподтверждённости не влияла на исход.
        app(UserRepository::class)->update($user, ['email_verified_at' => null]);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'buyer@example.test',
            'password' => 'whatever-password',
            'password_confirmation' => 'whatever-password',
        ])->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_TOKEN_INVALID');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@example.test',
            'password' => 'old-password-value',
        ])->assertOk();
    }

    // ── verify email ─────────────────────────────────────────────────────────

    public function test_verify_email_requires_an_authenticated_session(): void
    {
        $user = $this->makeUser('buyer@example.test');
        $token = app(AccountTokenService::class)->issue($user, AccountTokenPurpose::EmailVerification);

        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])
            ->assertStatus(401);
    }

    public function test_verify_email_marks_the_address_verified(): void
    {
        $user = $this->makeUser('buyer@example.test');

        // Регистрация сейчас ставит `email_verified_at`, поэтому отменяем его —
        // иначе проверять нечего. Это ровно та причина, по которой поток
        // верификации был не реализован (см. AuthController).
        app(UserRepository::class)->update($user, ['email_verified_at' => null]);

        $token = app(AccountTokenService::class)->issue($user, AccountTokenPurpose::EmailVerification);
        $session = $this->login($user, 'user-password-value');

        $this->withToken($session)
            ->postJson('/api/v1/auth/email/verify', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'buyer@example.test');

        self::assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verify_email_rejects_a_bad_token(): void
    {
        $user = $this->makeUser('buyer@example.test');
        app(UserRepository::class)->update($user, ['email_verified_at' => null]);

        $session = $this->login($user, 'user-password-value');

        $this->withToken($session)
            ->postJson('/api/v1/auth/email/verify', ['token' => 'garbage'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EMAIL_VERIFICATION_TOKEN_INVALID');

        self::assertNull($user->fresh()->email_verified_at);
    }

    public function test_account_emails_are_not_sent_when_the_template_is_disabled(): void
    {
        NotificationTemplate::query()
            ->where('code', AccountNotificationCodes::PASSWORD_RESET)
            ->update(['active' => false]);

        $this->makeUser('buyer@example.test');

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'buyer@example.test'])
            ->assertNoContent();

        Mail::assertNothingOutgoing();
        self::assertSame(0, Notification::query()->count());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeUser(string $email, string $password = 'user-password-value'): User
    {
        return app(UserRepository::class)->create([
            'email' => $email,
            'password' => $password,
            'first_name' => 'Покупатель',
            'last_name' => 'Тестовый',
        ]);
    }

    /** @return string bearer token */
    private function login(User $user, string $password): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk();

        return (string) $response->json('data.token');
    }

    private function seedTemplates(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
    }
}
