<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Core\Users\Services\CustomerAccountService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Личный кабинет — пространство `/api/v1/me/*`.
 *
 * ЧТО ИМЕННО ЗДЕСЬ ЗАЩИЩАЕТСЯ, И ПОЧЕМУ КАЖДЫЙ ТЕСТ ЕСТЬ
 *
 *   Пять путей `/me/*` объявлены в контракте и не обслуживались ни одним
 *   маршрутом (`docs/REVIEW-spec-bundle.md` §3.24.4). Реализовать их было
 *   недостаточно: у каждого есть свой способ сломаться незаметно.
 *
 *   1. ВЛАДЕЛЕЦ. Ни один метод не принимает идентификатор пользователя — он
 *      берётся из сессии. Тесты `..._only_my_...` и `..._foreign_session_...`
 *      проверяют именно это: подстановка чужого `user_id` в query не расширяет
 *      выборку, чужая сессия неотличима от несуществующей.
 *   2. АДРЕС. Смена e-mail проходит через `UserIdentityPolicy`, потому что схема
 *      не мешает перенести подтверждённый адрес вместе с флагом подтверждения
 *      (REVIEW §3.17). Тесты `..._verified_address` и `..._unverified_address`
 *      фиксируют обе стороны правила.
 *   3. ПАРОЛЬ. Смена пароля отзывает ВСЕ сессии, включая текущую — это правило
 *      проекта, а не побочный эффект, и оно проверяется (`..._revokes_every_session`).
 *      Отдельно проверяется аккаунт БЕЗ пароля: он заведён по факту покупки, и
 *      требовать от него «текущий пароль» было бы требованием предъявить то,
 *      чего нет.
 *   4. СЕССИИ. Список не должен отдавать `session_token_hash`: это sha256 от
 *      Bearer-токена, и утечка списка стала бы утечкой учётных данных.
 */
class MeEndpointsTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PASSWORD = 'Kv7#mRta2!Qz';
    private const EMAIL = 'buyer@example.com';

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: int} [bearer-токен, users.id] */
    private function registerAndLogin(string $email = self::EMAIL, string $password = self::PASSWORD): array
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ])->assertStatus(201);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ])->assertStatus(200)->json('data.token');

        return [$token, (int) DB::table('users')->where('email', $email)->value('id')];
    }

    /** Аккаунт покупателя без пароля — так его заводит чекаут. */
    private function accountWithoutPassword(string $email = self::EMAIL): int
    {
        $id = $this->app->make(CustomerAccountService::class)->resolveForCheckout($email, 'Иван Петров');

        $this->assertNotNull($id);

        return (int) $id;
    }

    /** Bearer-токен для существующего аккаунта — без пароля и без HTTP-входа. */
    private function tokenFor(int $userId): string
    {
        return $this->app->make(SessionIssuer::class)->issue($userId);
    }

    /**
     * Заказ покупателя. Возвращает `public_id` — именно его отдаёт API
     * (`OrderResource`), поэтому сравнивать с ответом надо этим значением.
     */
    private function seedOrder(int $organizationId, ?int $userId, string $email = self::EMAIL, string $status = 'pending'): string
    {
        $now = now()->toDateTimeString();
        $publicId = (string) Str::ulid()->toBase32();

        DB::table('orders')->insert([
            'public_id' => $publicId,
            'order_number' => 'NB-20261013-' . strtoupper(Str::random(6)),
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'subtotal_amount' => 5_000_000,
            'total_amount' => 5_000_000,
            'currency' => 'RUB',
            'status' => $status,
            'payment_status' => $status === 'paid' ? 'succeeded' : 'pending',
            'customer_email' => $email,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $publicId;
    }

    /** Внутренний BIGINT заказа — только для посева зависимых сущностей. */
    private function internalOrderId(string $publicId): int
    {
        return (int) DB::table('orders')->where('public_id', $publicId)->value('id');
    }

    /** Билет на заказ: то, что отдаёт `GET /my-tickets`. */
    private function seedTicket(int $orderId, int $eventId, int $sessionId, int $inventoryItemId): string
    {
        $now = now()->toDateTimeString();
        $ticketNumber = 'TCK-' . strtoupper(Str::random(8));

        $orderItemId = (int) DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
            'unit_price' => 5_000_000,
            'total_amount' => 5_000_000,
            'event_title_snapshot' => 'Тестовое событие',
            'created_at' => $now,
        ]);

        DB::table('tickets')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'ticket_number' => $ticketNumber,
            'ticket_index' => 1,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'event_id' => $eventId,
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'status' => 'issued',
            'qr_version' => 1,
            'qr_token_hash' => hash('sha256', Str::random(16)),
            'qr_payload' => 'NB1.' . Str::random(26) . '.token.signature',
            'issued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $ticketNumber;
    }

    // ── 1. GET /me ──────────────────────────────────────────────────────────

    /**
     * `id` — публичный идентификатор, а не внутренний BIGINT.
     *
     * Схема `User` объявляет `id: {type: string}`, и все остальные ручки
     * адресуют пользователя по `users.public_id`. Возвращать здесь число значило
     * бы отдать идентификатор, которым нельзя воспользоваться нигде.
     */
    public function test_get_me_returns_the_contract_user_shape(): void
    {
        [$token, $userId] = $this->registerAndLogin();

        $response = $this->withToken($token)->getJson('/api/v1/me')->assertStatus(200);

        $publicId = (string) DB::table('users')->where('id', $userId)->value('public_id');

        $response
            ->assertJsonPath('data.id', $publicId)
            ->assertJsonPath('data.email', self::EMAIL)
            ->assertJsonPath('data.first_name', 'Иван')
            ->assertJsonPath('data.last_name', 'Петров')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.locale', 'ru')
            ->assertJsonPath('data.timezone', 'UTC');

        $this->assertSame(26, strlen((string) $response->json('data.id')), 'ULID base32 — 26 символов');
    }

    public function test_get_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    // ── 2. PATCH /me ────────────────────────────────────────────────────────

    public function test_patch_me_updates_the_profile(): void
    {
        [$token, $userId] = $this->registerAndLogin();

        $this->withToken($token)->patchJson('/api/v1/me', [
            'first_name' => 'Мария',
            'last_name' => 'Сидорова',
            'phone' => '+7 900 000-00-00',
            'timezone' => 'Asia/Almaty',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.first_name', 'Мария')
            ->assertJsonPath('data.last_name', 'Сидорова')
            ->assertJsonPath('data.timezone', 'Asia/Almaty');

        $this->assertSame('+7 900 000-00-00', (string) DB::table('users')->where('id', $userId)->value('phone'));
    }

    /** Адрес, занятый другим аккаунтом, — ошибка поля `email`, а не 500. */
    public function test_patch_me_refuses_an_address_held_by_another_account(): void
    {
        [$token, $userId] = $this->registerAndLogin();

        $this->postJson('/api/v1/auth/register', [
            'email' => 'other@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'first_name' => 'Пётр',
            'last_name' => 'Второй',
        ])->assertStatus(201);

        $response = $this->withToken($token)
            ->patchJson('/api/v1/me', ['email' => 'other@example.com'])
            ->assertStatus(422);

        $this->assertArrayHasKey('email', (array) $response->json('error.details.fields'));
        $this->assertSame(
            self::EMAIL,
            (string) DB::table('users')->where('id', $userId)->value('email'),
            'отказ обязан произойти до записи',
        );
    }

    /**
     * Тот же адрес в другом регистре — это ТОТ ЖЕ адрес, а не занятый.
     *
     * Колонка `users.email` — `utf8mb4_unicode_ci`, поэтому MySQL считает
     * `Buyer@Example.com` и `buyer@example.com` одним значением. Владелец этого
     * правила — `ContactChannel::sameIdentity()`, и домен им уже пользуется:
     * `UserIdentityPolicy::addressChangeDecision()` на такую правку отвечает
     * «адрес не изменился» и подтверждение не снимает.
     *
     * `UserService::updateUser()` решал тот же вопрос иначе — `$data['email']
     * !== $user->email` в PHP, а затем `findByEmail()` в SQL. Две проверки одного
     * правила отвечали по-разному: PHP говорил «адрес другой», SQL находил
     * СОБСТВЕННУЮ строку пользователя и сообщал «адрес уже зарегистрирован».
     * То есть сохранить свой же адрес, набрав его в другом регистре, было
     * невозможно — 422 про чужой аккаунт.
     *
     * Сторож невакциозен: без нормализации в `updateUser()` ответ 422.
     */
    public function test_patch_me_accepts_its_own_address_written_in_another_case(): void
    {
        [$token, $userId] = $this->registerAndLogin();

        $this->withToken($token)
            ->patchJson('/api/v1/me', ['email' => 'Buyer@Example.com'])
            ->assertStatus(200)
            ->assertJsonPath('data.email', self::EMAIL);

        $this->assertSame(
            self::EMAIL,
            (string) DB::table('users')->where('id', $userId)->value('email'),
            'адрес остаётся каноническим: правка регистра не создаёт второй адрес',
        );
        $this->assertNotNull(
            DB::table('users')->where('id', $userId)->value('email_verified_at'),
            'подтверждение не снимается: адрес не менялся',
        );
    }

    /**
     * Подтверждённый адрес нельзя перенести правкой профиля.
     *
     * Схема этого не запрещает: `UPDATE users SET email = …` на MySQL 8.4
     * проходил с НЕОЧИЩЕННЫМ `email_verified_at`, и аккаунт начинал «владеть»
     * адресом, который никто не подтверждал (REVIEW §3.17). Правило живёт в
     * `UserIdentityPolicy::addressChangeDecision()`.
     */
    public function test_patch_me_refuses_to_move_a_verified_address(): void
    {
        [$token, $userId] = $this->registerAndLogin();

        DB::table('users')->where('id', $userId)->update(['email_verified_at' => now()]);

        $this->withToken($token)
            ->patchJson('/api/v1/me', ['email' => 'moved@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EMAIL_CHANGE_REJECTED');

        $this->assertSame(self::EMAIL, (string) DB::table('users')->where('id', $userId)->value('email'));
        $this->assertNotNull(
            DB::table('users')->where('id', $userId)->value('email_verified_at'),
            'флаг подтверждения обязан остаться на прежнем адресе',
        );
    }

    /** Неподтверждённый адрес меняется свободно и остаётся неподтверждённым. */
    public function test_patch_me_changing_an_unverified_address_keeps_it_unverified(): void
    {
        [$token, $userId] = $this->registerAndLogin();

        DB::table('users')->where('id', $userId)->update(['email_verified_at' => null]);

        $this->withToken($token)
            ->patchJson('/api/v1/me', ['email' => 'new@example.com'])
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'new@example.com');

        $row = DB::table('users')->where('id', $userId)->first();

        $this->assertSame('new@example.com', $row->email);
        $this->assertNull($row->email_verified_at, 'новый адрес никем не подтверждён');
    }

    // ── 3. POST /me/password ────────────────────────────────────────────────

    public function test_change_password_refuses_a_wrong_current_password(): void
    {
        [$token] = $this->registerAndLogin();

        $this->withToken($token)->postJson('/api/v1/me/password', [
            'current_password' => 'WrongPass1!aa',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CURRENT_PASSWORD_INVALID');
    }

    /**
     * Успешная смена пароля отзывает ВСЕ сессии — включая ту, которой её вызвали.
     *
     * Это правило проекта, а не побочный эффект: `UserService::updateUser()`
     * отзывает сессии при смене пароля, и `AccountRecoveryService::resetPassword()`
     * делает то же самое. Смысл — «выйти везде»: смена пароля часто означает
     * подозрение, что кто-то уже вошёл, и оставлять чужую сессию живой значило бы
     * закрыть дверь, не выгнав того, кто внутри.
     */
    public function test_change_password_rotates_the_password_and_revokes_every_session(): void
    {
        [$token] = $this->registerAndLogin();

        $this->withToken($token)->postJson('/api/v1/me/password', [
            'current_password' => self::PASSWORD,
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ])->assertStatus(204);

        $this->assertSame(0, DB::table('user_sessions')->count(), 'все сессии обязаны быть отозваны');

        // Токен, которым меняли пароль, больше не работает.
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);

        // Старый пароль не подходит, новый — подходит.
        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => 'NewStrongPass1!',
        ])->assertStatus(200);
    }

    /**
     * Аккаунт без пароля задаёт его, не предъявляя «текущий».
     *
     * Такой аккаунт заводит чекаут (`CustomerAccountService`: `password = NULL`).
     * Требование текущего пароля сделало бы эндпоинт недостижимым: `Hash::check()`
     * с пустым хешем всегда false, и покупатель мог бы задать пароль только через
     * письмо. Предъявлять нечего — владение уже доказано Bearer-сессией.
     */
    public function test_an_account_without_a_password_can_set_one_without_the_current_one(): void
    {
        $userId = $this->accountWithoutPassword();
        $token = $this->tokenFor($userId);

        $this->withToken($token)->postJson('/api/v1/me/password', [
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ])->assertStatus(204);

        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => 'NewStrongPass1!',
        ])->assertStatus(200);
    }

    // ── 4. Сессии ───────────────────────────────────────────────────────────

    /** Список не отдаёт `session_token_hash` и не выходит за пределы аккаунта. */
    public function test_list_sessions_never_exposes_the_token_hash(): void
    {
        [$token, $userId] = $this->registerAndLogin();

        [, $otherId] = $this->registerAndLogin('other@example.com');
        $otherSessionId = $this->tokenFor($otherId);

        $response = $this->withToken($token)->getJson('/api/v1/me/sessions')->assertStatus(200);

        $rows = (array) $response->json('data');

        // Сравнение идёт с фактическим содержимым таблицы, а не с «ожидаемой
        // единицей»: `POST /auth/register` и `POST /auth/login` выдают по сессии
        // каждый, поэтому у аккаунта после `registerAndLogin()` их две. Тест
        // обязан проверять СКОУП, а не угаданное количество.
        $expected = DB::table('user_sessions')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $returned = array_map(
            static fn (array $row): string => (string) $row['id'],
            array_values($rows),
        );

        sort($expected);
        sort($returned);

        $this->assertSame($expected, $returned, 'список обязан совпасть с сессиями этого аккаунта');
        $this->assertNotEmpty($returned, 'предусловие: у вызывающего есть хотя бы одна сессия');

        $otherSessionRowId = (int) DB::table('user_sessions')->where('user_id', $otherId)->value('id');
        $this->assertNotContains((string) $otherSessionRowId, $returned, 'чужая сессия не попадает в список');
        $this->assertNotSame('', $otherSessionId);

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('session_token_hash', $row);
            $this->assertArrayNotHasKey('user_id', $row);
            $this->assertArrayNotHasKey('ip_address', $row);
            $this->assertArrayHasKey('created_at', $row);
        }
    }

    public function test_revoking_a_foreign_session_is_a_404(): void
    {
        [$token] = $this->registerAndLogin();

        [, $otherId] = $this->registerAndLogin('other@example.com');
        $this->tokenFor($otherId);

        $foreignSessionId = (int) DB::table('user_sessions')->where('user_id', $otherId)->value('id');

        $this->withToken($token)
            ->deleteJson('/api/v1/me/sessions/' . $foreignSessionId)
            ->assertStatus(404);

        $this->assertSame(
            1,
            DB::table('user_sessions')->where('id', $foreignSessionId)->count(),
            'чужая сессия обязана остаться: 404 не должен ничего удалять',
        );
    }

    public function test_revoking_my_session_ends_it(): void
    {
        [$token, $userId] = $this->registerAndLogin();

        // Ищем сессию ИМЕННО этого токена: `registerAndLogin()` делает два
        // запроса, и каждый выдаёт по сессии, поэтому «первая строка» и «строка
        // предъявленного токена» — разные записи. `session_token_hash` — sha256
        // от токена в hex (см. `SessionToken::hashOf`).
        $sessionId = (int) DB::table('user_sessions')
            ->where('user_id', $userId)
            ->where('session_token_hash', hash('sha256', $token))
            ->value('id');

        $this->assertGreaterThan(0, $sessionId, 'предусловие: токену соответствует строка сессии');

        $this->withToken($token)->deleteJson('/api/v1/me/sessions/' . $sessionId)->assertStatus(204);

        $this->assertSame(0, DB::table('user_sessions')->where('id', $sessionId)->count());
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }

    // ── 5. GET /me/orders ───────────────────────────────────────────────────

    /**
     * `/me/orders` отдаёт только свои заказы — и ДЛЯ СОТРУДНИКА ТОЖЕ.
     *
     * Именно это отличает его от `GET /orders`: там фильтр `user_id` читается из
     * query и для admin/manager не применяется вовсе. Если бы `/me/orders` просто
     * ссылался на `index()`, сотрудник в своём кабинете увидел бы заказы всей
     * системы — то есть путь «мои» перестал бы означать «мои».
     */
    public function test_my_orders_lists_only_my_orders_even_for_a_staff_account(): void
    {
        [$organizationId] = $this->seedSellableSeat(available: 1);

        [$token, $userId] = $this->registerAndLogin();
        [, $otherId] = $this->registerAndLogin('other@example.com');

        $mine = $this->seedOrder($organizationId, $userId);
        $theirs = $this->seedOrder($organizationId, $otherId, 'other@example.com');

        // Сотрудник: роль admin в организации.
        $now = now()->toDateTimeString();
        $roleId = (int) DB::table('roles')->insertGetId(['name' => 'Admin', 'slug' => 'admin']);
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/me/orders')->assertStatus(200);

        $ids = array_column((array) $response->json('data'), 'id');

        $this->assertContains($mine, $ids);
        $this->assertNotContains($theirs, $ids, 'чужой заказ не попадает в «мои» даже у сотрудника');

        // Подстановка чужого user_id в query выборку не расширяет.
        $widened = $this->withToken($token)
            ->getJson('/api/v1/me/orders?user_id=' . $otherId)
            ->assertStatus(200);

        $this->assertSame(
            $ids,
            array_column((array) $widened->json('data'), 'id'),
            'query-параметр не имеет права расширять «мои» заказы',
        );
    }

    // ── 6. GET /my-tickets по аккаунту ──────────────────────────────────────

    /**
     * Вошедший покупатель видит билеты БЕЗ гостевого токена корзины.
     *
     * Раньше «мои билеты» находились только по `carts.cart_token` и требовали
     * токен длиной ≥ 32 символов. Вошедшему покупателю корзина выдаёт короткий
     * токен `'u'.$id`, поэтому он получал ПУСТОЙ список — то есть смена браузера
     * или очистка localStorage означала потерю билетов навсегда, хотя заказ
     * существовал и был привязан к аккаунту.
     */
    public function test_my_tickets_resolves_by_account_without_a_cart_token(): void
    {
        [$organizationId, $eventId, $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1);

        $userId = $this->accountWithoutPassword();
        $token = $this->tokenFor($userId);

        $orderPublicId = $this->seedOrder($organizationId, $userId, self::EMAIL, 'paid');
        $ticketNumber = $this->seedTicket(
            $this->internalOrderId($orderPublicId),
            $eventId,
            $sessionId,
            $inventoryItemId,
        );

        $response = $this->withToken($token)->getJson('/api/v1/my-tickets')->assertStatus(200);

        $codes = array_column((array) $response->json('data'), 'code');

        $this->assertContains($ticketNumber, $codes, 'билет обязан находиться по владельцу заказа');

        // Без токена и без входа — по-прежнему пустой список, а не 401:
        // витрина гостевая, и гость должен видеть пустой экран.
        //
        // `flushHeaders()` обязателен: `withToken()` ставит заголовок на самом
        // TestCase и он переживает следующий запрос — без сброса «запрос без
        // токена» унёс бы Bearer предыдущего и вернул тот же билет.
        $this->flushHeaders();

        $this->getJson('/api/v1/my-tickets')
            ->assertStatus(200)
            ->assertExactJson(['data' => []]);
    }

    /** Чужой билет по аккаунту не отдаётся. */
    public function test_my_tickets_does_not_leak_another_accounts_tickets(): void
    {
        [$organizationId, $eventId, $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1);

        [, $otherId] = $this->registerAndLogin('other@example.com');
        $otherOrderPublicId = $this->seedOrder($organizationId, $otherId, 'other@example.com', 'paid');
        $this->seedTicket(
            $this->internalOrderId($otherOrderPublicId),
            $eventId,
            $sessionId,
            $inventoryItemId,
        );

        $mineId = $this->accountWithoutPassword();
        $mineToken = $this->tokenFor($mineId);

        $this->withToken($mineToken)
            ->getJson('/api/v1/my-tickets')
            ->assertStatus(200)
            ->assertExactJson(['data' => []]);
    }
}
