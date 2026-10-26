<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Nabilet\Modules\Auth\Domain\OAuthState;
use Nabilet\Modules\Auth\Domain\TelegramInitData;
use Tests\TestCase;

/**
 * Соц-вход: Telegram Mini App и OAuth Яндекс/VK (ТЗ §17).
 *
 * ЧТО ИМЕННО ЗДЕСЬ ЗАЩИЩАЕТСЯ
 *
 *   Три провайдера — три разных способа провалиться, и все три выглядят снаружи
 *   как «вход не сработал»:
 *
 *   1. ПОДПИСЬ TELEGRAM. `initData` приходит из браузера, то есть от кого угодно.
 *      Тесты требуют ОТКАЗ на подделку и на протухшую дату — и требуют, чтобы
 *      отказ был 401 в конверте §66, а не 500: невалидные данные это не сбой
 *      сервера. Отдельно проверяется незаданный конфиг: без bot token вход
 *      обязан отвечать 401, а не падать и не «пропускать без проверки».
 *
 *   2. `state` (login CSRF). Callback принимает `code` из адресной строки, а
 *      значит любой валидный чужой `code` вошёл бы в аккаунт злоумышленника.
 *      Поэтому начало входа (`/auth/{provider}/start`) выдаёт `state` в
 *      `HttpOnly`-куку, а callback сверяет его ДО обмена кода. Тесты проверяют
 *      обе стороны: без куки и с чужим значением — отказ и ни одного
 *      созданного аккаунта.
 *
 *   3. СВЯЗЫВАНИЕ АККАУНТА. Правило разрешения живёт в одном месте
 *      (`SocialAuthService`) и здесь проверяется со всех сторон: тот же
 *      telegram-пользователь второй раз не создаёт вторую строку; подтверждённый
 *      провайдером e-mail входит в СУЩЕСТВУЮЩИЙ аккаунт, а не заводит дубль;
 *      второй провайдер с тем же адресом привязывается к тому же человеку.
 *      Без этих тестов «вход через ВК» тихо создавал бы второй аккаунт с тем же
 *      адресом — и покупатель терял бы свои билеты.
 *
 *   4. ТРАНСПОРТ. Callback — web-маршрут без `/api/v1`: браузер возвращается
 *      сюда с сайта провайдера, и ответ обязан быть 302 на витрину, а не JSON.
 *      Тест на `/api/v1/auth/yandex/callback` → 404 фиксирует именно это: если
 *      маршрут когда-нибудь «переедет» в API-группу, живой вход сломается, а
 *      остальные тесты этого не заметят.
 *
 *   5. ТОКЕН. Соц-вход выпускает сессию тем же `SessionIssuer`, что и пароль,
 *      поэтому полученный токен обязан работать в `/me` — иначе «вход» создавал
 *      бы пользователя, с которым нельзя ничего сделать.
 */
class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '123456789:AAHtest-token-for-feature-tests';

    private const TELEGRAM_ID = 987654321;

    protected function setUp(): void
    {
        parent::setUp();

        // `throttle:auth` — 5 запросов в минуту с одного IP. Тесты входа делают
        // по нескольку обращений, и без сброса счётчика они начали бы падать на
        // 429 через прогон, а не на своей причине. Лимитер НЕ отключается:
        // проверять его — дело `RateLimitTest`, а здесь он должен оставаться
        // живым, просто не накопленным с прошлого теста.
        RateLimiter::clear('auth:127.0.0.1');
        RateLimiter::clear('auth-ip:127.0.0.1');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function telegramInitData(int $authDate = 0, array $overrides = []): string
    {
        return TelegramInitData::sign(
            $overrides + [
                'auth_date' => $authDate === 0 ? time() : $authDate,
                'query_id' => 'AAHfeature',
                'user' => [
                    'id' => self::TELEGRAM_ID,
                    'first_name' => 'Иван',
                    'last_name' => 'Телеграмов',
                    'username' => 'ivan_tg',
                ],
            ],
            self::BOT_TOKEN,
        );
    }

    private function configureTelegram(): void
    {
        config(['services.telegram.bot_token' => self::BOT_TOKEN]);
    }

    private function configureYandex(): void
    {
        config([
            'services.yandex.client_id' => 'ya-client',
            'services.yandex.client_secret' => 'ya-secret',
            'services.yandex.redirect_uri' => 'https://nabilet.test/auth/yandex/callback',
        ]);
    }

    private function configureVk(): void
    {
        config([
            'services.vk.client_id' => 'vk-client',
            'services.vk.client_secret' => 'vk-secret',
            'services.vk.redirect_uri' => 'https://nabilet.test/auth/vk/callback',
        ]);
    }

    /**
     * Яндекс: обмен кода на токен, затем профиль.
     *
     * Подмены задаются ПО ШАБЛОНУ URL, а не одной функцией-перехватчиком:
     * `Http::fake()` при повторном вызове не заменяет прежние подмены, а
     * ДОБАВЛЯЕТ их. Функция-перехватчик, повешенная для Яндекса, перехватила бы
     * и запросы VK — тест на связывание двух провайдеров падал бы на
     * `exchange_failed` вместо того, чтобы проверять связывание. Шаблоны
     * сосуществуют, потому что выбираются по URL.
     */
    private function fakeYandex(string $id = '100500', string $email = 'ya@example.com'): void
    {
        Http::fake([
            'oauth.yandex.ru/token*' => Http::response([
                'access_token' => 'ya-access-token',
                'token_type' => 'bearer',
            ], 200),
            'login.yandex.ru/info*' => Http::response([
                'id' => $id,
                'default_email' => $email,
                'first_name' => 'Иван',
                'last_name' => 'Яндексов',
                'default_avatar_id' => 'avatar-1',
            ], 200),
        ]);
    }

    /** VK: токен и профиль одним методом API. */
    private function fakeVk(string $id = '777', string $email = 'ya@example.com'): void
    {
        Http::fake([
            'oauth.vk.com/access_token*' => Http::response([
                'access_token' => 'vk-access-token',
                'user_id' => (int) $id,
                'email' => $email,
            ], 200),
            'api.vk.com/method/users.get*' => Http::response([
                'response' => [[
                    'id' => (int) $id,
                    'first_name' => 'Иван',
                    'last_name' => 'Вконтактов',
                    'photo_200' => 'https://vk.test/photo.jpg',
                ]],
            ], 200),
        ]);
    }

    /** Параметры из `Location` редиректа (query-строка или фрагмент после `?`). */
    private function queryOf(string $location): array
    {
        $position = strpos($location, '?');

        if ($position === false) {
            return [];
        }

        parse_str(substr($location, $position + 1), $query);

        return $query;
    }

    /**
     * Начать вход: `/auth/{provider}/start` → `state`, который приложение
     * выдало этому браузеру.
     *
     * Кука в браузере и кука в запросе — разные вещи, поэтому `state` берётся из
     * authorize-URL, а в callback уезжает через `withCookie()` (значение
     * шифруется так же, как его шифрует `EncryptCookies` на выходе). Тест таким
     * образом проверяет и то, что `start` выдаёт значение, и то, что `callback`
     * сверяет именно его.
     */
    private function beginOAuth(string $provider): string
    {
        $location = (string) $this->get('/auth/' . $provider . '/start')
            ->assertStatus(302)
            ->headers->get('Location');

        $state = (string) ($this->queryOf($location)['state'] ?? '');

        $this->assertNotSame('', $state, 'начало входа обязано выдать state: ' . $location);

        return $state;
    }

    /** Пройти вход целиком: start → callback. Возвращает `Location` callback'а. */
    private function completeOAuth(string $provider, string $code): string
    {
        $state = $this->beginOAuth($provider);

        return (string) $this->withCookie(OAuthState::COOKIE, $state)
            ->get('/auth/' . $provider . '/callback?code=' . urlencode($code) . '&state=' . urlencode($state))
            ->assertStatus(302)
            ->headers->get('Location');
    }

    /** Токен из `Location` редиректа на витрину (`…/#/auth/callback?token=…`). */
    private function tokenFromRedirect(string $location): string
    {
        $token = (string) ($this->queryOf($location)['token'] ?? '');

        $this->assertNotSame('', $token, 'редирект обязан нести токен: ' . $location);

        return $token;
    }

    private function userIdByEmail(?string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    // ── 1. Telegram ─────────────────────────────────────────────────────────

    /**
     * Вход создаёт аккаунт БЕЗ почты и БЕЗ пароля — и выдаёт работающий токен.
     *
     * Telegram не отдаёт e-mail, поэтому `users.email` и `users.password`
     * остаются NULL. Это не побочный эффект, а причина, по которой схема
     * допускает NULL: иначе соц-вход было бы некуда записать.
     */
    public function test_telegram_auth_creates_an_account_without_email_or_password(): void
    {
        $this->configureTelegram();

        $response = $this->postJson('/api/v1/telegram/auth', ['init_data' => $this->telegramInitData()])
            ->assertStatus(200);

        $token = (string) $response->json('data.token');
        $publicId = (string) $response->json('data.user.id');

        $this->assertSame('Bearer', $response->json('data.token_type'));
        $this->assertNotSame('', $token, 'вход обязан выдать токен');

        $row = DB::table('users')->first();

        $this->assertNotNull($row, 'аккаунт обязан быть создан');
        $this->assertNull($row->email, 'Telegram не отдаёт почту — адрес остаётся NULL');
        $this->assertNull($row->password, 'у соц-входа нет пароля');
        $this->assertSame('Иван', $row->first_name);
        $this->assertSame('Телеграмов', $row->last_name);
        $this->assertSame('active', $row->status);
        $this->assertSame((string) $row->public_id, $publicId, '`data.user.id` — публичный идентификатор');

        $link = DB::table('social_accounts')->first();

        $this->assertNotNull($link, 'связь с провайдером обязана быть записана');
        $this->assertSame('telegram', $link->provider);
        $this->assertSame((string) self::TELEGRAM_ID, $link->provider_user_id);
        $this->assertSame((int) $row->id, (int) $link->user_id);

        // Токен соц-входа — обычная сессия: он обязан работать в `/me`.
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $publicId);
    }

    /** Повторный вход тем же telegram-пользователем не создаёт второй аккаунт. */
    public function test_telegram_auth_is_idempotent_for_the_same_telegram_user(): void
    {
        $this->configureTelegram();

        $first = $this->postJson('/api/v1/telegram/auth', ['init_data' => $this->telegramInitData()])
            ->assertStatus(200)
            ->json('data.user.id');

        $second = $this->postJson('/api/v1/telegram/auth', ['init_data' => $this->telegramInitData()])
            ->assertStatus(200)
            ->json('data.user.id');

        $this->assertSame($first, $second, 'второй вход — тот же человек');
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('social_accounts')->count());
    }

    /**
     * Подделанная подпись — 401 в конверте §66 и ни одного созданного аккаунта.
     *
     * Подмена `query_id` при старом `hash` — ровно то, что попытался бы сделать
     * клиент, решивший «дописать» себе поля.
     */
    public function test_telegram_auth_rejects_a_forged_signature(): void
    {
        $this->configureTelegram();

        $forged = str_replace('AAHfeature', 'AAHforged', $this->telegramInitData());
        $this->assertStringContainsString('AAHforged', $forged, 'предусловие: подмена произошла');

        $this->postJson('/api/v1/telegram/auth', ['init_data' => $forged])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TELEGRAM_AUTH');

        $this->assertSame(0, DB::table('users')->count(), 'отказ обязан произойти до создания аккаунта');
        $this->assertSame(0, DB::table('social_accounts')->count());
    }

    /** Протухший `auth_date` — тот же отказ, что и подделка. */
    public function test_telegram_auth_rejects_stale_init_data(): void
    {
        $this->configureTelegram();

        $stale = $this->telegramInitData(time() - TelegramInitData::DEFAULT_MAX_AGE_SECONDS - 60);

        $this->postJson('/api/v1/telegram/auth', ['init_data' => $stale])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TELEGRAM_AUTH');

        $this->assertSame(0, DB::table('users')->count());
    }

    /**
     * Незаданный bot token — 401, а не 500 и не «вход без проверки».
     *
     * Самый опасный вариант отказа здесь — «пропустить, раз проверить нечем».
     * Поэтому пустой токен конфигурации означает «вход невозможен», а не
     * «подпись не проверяем».
     */
    public function test_telegram_auth_fails_closed_when_the_bot_token_is_not_configured(): void
    {
        config(['services.telegram.bot_token' => '']);

        $this->postJson('/api/v1/telegram/auth', ['init_data' => $this->telegramInitData()])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TELEGRAM_AUTH');

        $this->assertSame(0, DB::table('users')->count());
    }

    /** Пустое тело — тот же 401, без падения на разборе. */
    public function test_telegram_auth_rejects_an_empty_body(): void
    {
        $this->configureTelegram();

        $this->postJson('/api/v1/telegram/auth', [])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TELEGRAM_AUTH');
    }

    // ── 2. Начало входа и `state` ───────────────────────────────────────────

    /**
     * `/start` уводит на страницу провайдера и кладёт `state` в HttpOnly-куку.
     *
     * `HttpOnly` проверяется отдельно: значение не должен читать ни фронт, ни
     * скрипт, попавший на страницу, — сверяет его только сервер.
     */
    public function test_start_redirects_to_the_provider_with_an_httponly_state_cookie(): void
    {
        $this->configureYandex();

        $response = $this->get('/auth/yandex/start')->assertStatus(302);

        $location = (string) $response->headers->get('Location');

        $this->assertStringContainsString('oauth.yandex.ru/authorize', $location);
        $this->assertStringContainsString('client_id=ya-client', $location);
        $this->assertStringContainsString(
            'redirect_uri=' . rawurlencode('https://nabilet.test/auth/yandex/callback'),
            $location,
        );

        $state = (string) ($this->queryOf($location)['state'] ?? '');
        $this->assertNotSame('', $state, 'authorize-URL обязан нести state');

        $cookie = collect($response->headers->getCookies())
            ->first(static fn ($c): bool => $c->getName() === OAuthState::COOKIE);

        $this->assertNotNull($cookie, 'start обязан поставить куку состояния');
        $this->assertTrue($cookie->isHttpOnly(), 'state не должен быть доступен скриптам');
        $this->assertSame('lax', $cookie->getSameSite(), 'возврат от провайдера — обычный GET-переход');
    }

    /** VK тоже получает свой authorize-URL — со своей версией API. */
    public function test_start_for_vk_points_at_the_vk_authorize_page(): void
    {
        $this->configureVk();

        $location = (string) $this->get('/auth/vk/start')
            ->assertStatus(302)
            ->headers->get('Location');

        $this->assertStringContainsString('oauth.vk.com/authorize', $location);
        $this->assertStringContainsString('client_id=vk-client', $location);
        $this->assertStringContainsString('v=5.199', $location);
    }

    /**
     * Ненастроенный провайдер возвращает на витрину с ошибкой, а не 500.
     *
     * Кнопку в этом случае фронт не рисует, но прямой заход по адресу не должен
     * выглядеть как сбой сервера.
     */
    public function test_start_without_configuration_returns_to_the_storefront(): void
    {
        config([
            'services.yandex.client_id' => '',
            'services.yandex.client_secret' => '',
        ]);

        $location = (string) $this->get('/auth/yandex/start')
            ->assertStatus(302)
            ->headers->get('Location');

        $this->assertStringContainsString('error=provider_not_configured', $location);
    }

    /**
     * `state`, выданный не этому браузеру, — отказ.
     *
     * Это и есть login CSRF: чужой валидный `code` без нашей куки не должен
     * никого никуда впустить.
     */
    public function test_callback_rejects_a_state_that_was_not_issued_to_this_browser(): void
    {
        $this->configureYandex();
        $this->fakeYandex();

        $location = (string) $this->get('/auth/yandex/callback?code=ya-code&state=anything')
            ->assertStatus(302)
            ->headers->get('Location');

        $this->assertStringContainsString('error=invalid_state', $location);
        $this->assertSame(0, DB::table('users')->count(), 'обмен не должен был начаться вовсе');
    }

    /** Кука есть, но значение в ней другое — тоже отказ. */
    public function test_callback_rejects_a_foreign_state_value(): void
    {
        $this->configureYandex();
        $this->fakeYandex();

        $location = (string) $this->withCookie(OAuthState::COOKIE, 'issued-to-someone-else')
            ->get('/auth/yandex/callback?code=ya-code&state=state-of-the-attacker')
            ->assertStatus(302)
            ->headers->get('Location');

        $this->assertStringContainsString('error=invalid_state', $location);
        $this->assertSame(0, DB::table('users')->count());
    }

    // ── 3. OAuth-callback Яндекс ────────────────────────────────────────────

    /**
     * Callback возвращает БРАУЗЕР на витрину с токеном во фрагменте.
     *
     * Фрагмент (`#/auth/callback?token=…`), а не query: hash-роутинг SPA иначе
     * не увидел бы параметр, а query-строка с токеном осталась бы в истории
     * браузера и в логах прокси.
     */
    public function test_yandex_callback_redirects_to_the_storefront_with_a_token(): void
    {
        $this->configureYandex();
        $this->fakeYandex();

        $location = $this->completeOAuth('yandex', 'ya-code');

        $this->assertStringContainsString('/#/auth/callback?token=', $location);

        $token = $this->tokenFromRedirect($location);

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'ya@example.com')
            ->assertJsonPath('data.first_name', 'Иван');

        $row = DB::table('users')->where('email', 'ya@example.com')->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->email_verified_at, 'провайдер подтвердил адрес — флаг ставится сразу');
        $this->assertSame('yandex', DB::table('social_accounts')->value('provider'));
        $this->assertSame('100500', (string) DB::table('social_accounts')->value('provider_user_id'));
    }

    /**
     * Подтверждённый провайдером адрес входит в СУЩЕСТВУЮЩИЙ аккаунт.
     *
     * Иначе человек, купивший билет по паролю, при входе через Яндекс получил бы
     * второй аккаунт с тем же адресом — и не увидел бы ни одного своего билета.
     * Уникальный индекс `users.email` сделал бы это ошибкой 500, а не «просто
     * дублем», поэтому тест проверяет и количество строк.
     */
    public function test_yandex_callback_links_an_existing_account_by_verified_email(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'ya@example.com',
            'password' => 'Kv7#mRta2!Qz',
            'password_confirmation' => 'Kv7#mRta2!Qz',
            'first_name' => 'Мария',
            'last_name' => 'Сидорова',
        ])->assertStatus(201);

        $existingId = $this->userIdByEmail('ya@example.com');
        $this->assertGreaterThan(0, $existingId);

        $this->configureYandex();
        $this->fakeYandex();

        $token = $this->tokenFromRedirect($this->completeOAuth('yandex', 'ya-code'));

        $this->assertSame(1, DB::table('users')->count(), 'второго аккаунта быть не должно');

        // Профиль существующего аккаунта не перезаписывается соц-данными:
        // вход доказывает личность, а не правит её.
        $row = DB::table('users')->where('id', $existingId)->first();
        $this->assertSame('Мария', $row->first_name);
        $this->assertSame((int) $existingId, (int) DB::table('social_accounts')->value('user_id'));

        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(200);
    }

    /**
     * Второй провайдер с тем же адресом — тот же человек, вторая связь.
     *
     * Таблица `social_accounts` отдельная именно поэтому: `users` не может
     * хранить один `provider_user_id`, а привязок у человека бывает несколько.
     */
    public function test_a_second_provider_links_to_the_same_account(): void
    {
        $this->configureYandex();
        $this->configureVk();

        $this->fakeYandex('100500', 'shared@example.com');
        $this->tokenFromRedirect($this->completeOAuth('yandex', 'ya'));

        $this->fakeVk('777', 'shared@example.com');
        $token = $this->tokenFromRedirect($this->completeOAuth('vk', 'vk'));

        $this->assertSame(1, DB::table('users')->count(), 'один адрес — один аккаунт');
        $this->assertSame(2, DB::table('social_accounts')->count(), 'две привязки у одного аккаунта');

        $providers = DB::table('social_accounts')->orderBy('provider')->pluck('provider')->all();
        $this->assertSame(['vk', 'yandex'], $providers);

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'shared@example.com');
    }

    // ── 4. Отказы callback ──────────────────────────────────────────────────

    /** Без `code` браузер возвращается на витрину с ошибкой, а не видит JSON. */
    public function test_callback_without_a_code_returns_to_the_storefront_with_an_error(): void
    {
        $this->configureYandex();

        $location = (string) $this->get('/auth/yandex/callback?state=only-state')
            ->assertStatus(302)
            ->headers->get('Location');

        $this->assertStringContainsString('error=missing_code', $location);
        $this->assertSame(0, DB::table('users')->count());
    }

    /**
     * Провайдер ответил отказом — 302 с `error`, а не 500.
     *
     * `report()` при этом вызывается: сбой обмена надо видеть в логах, но
     * человек обязан попасть на экран входа, а не на страницу ошибки.
     */
    public function test_callback_reports_an_exchange_failure_instead_of_a_server_error(): void
    {
        $this->configureYandex();

        Http::fake(static fn () => Http::response(['error' => 'invalid_grant'], 400));

        $location = $this->completeOAuth('yandex', 'bad-code');

        $this->assertStringContainsString('error=exchange_failed', $location);
        $this->assertSame(0, DB::table('users')->count());
    }

    /**
     * Неизвестный провайдер не маршрутизируется вовсе.
     *
     * `whereIn` в маршруте ограничивает сегмент словарём: `/auth/google/callback`
     * не должен доходить до контроллера как имя класса или как строка в реестре.
     */
    public function test_an_unsupported_provider_is_not_routed(): void
    {
        $this->get('/auth/google/start')->assertStatus(404);
        $this->get('/auth/google/callback?code=x&state=y')->assertStatus(404);
    }

    /**
     * Callback живёт в WEB-маршрутах, а не под `/api/v1`.
     *
     * Проверка кажется формальной, но она — сторож владельца маршрутов: если
     * файл когда-нибудь подключат из `routes/api.php`, путь станет
     * `/api/v1/auth/yandex/callback`, провайдер продолжит слать браузер на
     * старый адрес, и живой вход сломается при зелёных тестах контроллера.
     */
    public function test_the_callback_is_not_served_under_the_api_prefix(): void
    {
        $this->get('/api/v1/auth/yandex/callback?code=x&state=y')->assertStatus(404);
    }
}
