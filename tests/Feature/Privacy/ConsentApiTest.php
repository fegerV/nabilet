<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Support\PackedIp;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * API-флоу согласий субъекта-аккаунта (152-ФЗ §9): список и отзыв.
 *
 * ДО ЭТОГО
 *   Домен (`ConsentRecord`, `ConsentPolicy`, `ConsentDecision`) и модель были
 *   готовы и покрыты юнит-тестами, но HTTP-путей для согласий не существовало:
 *   владелец аккаунта не мог ни увидеть, на что давал согласие, ни отозвать его.
 *   Единственный работавший контур — рассылка, у которой своё согласие
 *   (anonymous-субъект) и своя отписка по токену.
 *
 * Здесь проверяется контракт HTTP:
 *
 *   1. гость получает 401 и на список, и на отзыв (`auth:api` на префиксе `me`);
 *   2. список пустого пользователя — `data: []`; список с двумя целями —
 *      ровно по одной последней строке на цель (не вся история);
 *   3. отзыв пишет НОВУЮ строку `withdrawn`: прежние строки не меняются,
 *      `withdrawn_at` записан (CHECK `ck_consents_withdrawn_has_moment`),
 *      `policy_version` наследуется от отзываемого согласия, ip/ua — из запроса;
 *   4. повторный отзыв — 200 с `written: false` и без новой записи;
 *   5. несуществующая цель — 404 `CONSENT_NOT_FOUND`, просроченное согласие —
 *      409 `CONSENT_NOT_WITHDRAWABLE` (отзывать нечего);
 *   6. история «grant → withdrawn → grant» отдаётся как granted — последнее
 *      решает; анонимные согласия (рассылка) в списке аккаунта не видны.
 */
class ConsentApiTest extends TestCase
{
    use RefreshDatabase;

    /* ── доступ: гость ───────────────────────────────────────────────────── */

    public function test_a_guest_gets_401_for_both_consent_endpoints(): void
    {
        $this->getJson('/api/v1/me/consents')->assertUnauthorized();
        $this->postJson('/api/v1/me/consents/marketing/withdraw')->assertUnauthorized();

        $this->assertDatabaseCount('consents', 0);
    }

    /* ── список ──────────────────────────────────────────────────────────── */

    public function test_an_empty_list_is_an_empty_array(): void
    {
        $user = $this->plainUser();

        $response = $this->actingAs($user, 'api')->getJson('/api/v1/me/consents');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_the_list_shows_one_latest_row_per_consent_type(): void
    {
        $user = $this->plainUser();

        // Два granted-эпизода одной цели: в ответе ожидается только последний,
        // а не обе строки истории.
        $this->seedConsent($user->id, 'marketing', 'granted', now()->subDays(2), '2026-01-01');
        $this->seedConsent($user->id, 'marketing', 'granted', now()->subDay(), '2026-07-01');
        $this->seedConsent($user->id, 'analytics', 'granted', now()->subHours(2), '2026-07-01');

        $response = $this->actingAs($user, 'api')->getJson('/api/v1/me/consents');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        // Сортировка списка — по цели (ksort): витрина сравнивает состояния.
        $first = $response->json('data.0');
        $second = $response->json('data.1');

        $this->assertSame('analytics', $first['consent_type']);
        $this->assertSame('granted', $first['status']);
        $this->assertNull($first['withdrawn_at']);
        $this->assertSame('2026-07-01', $first['policy_version']);

        $this->assertSame('marketing', $second['consent_type']);
        $this->assertSame('granted', $second['status']);
        // Последняя строка marketing'а — свежий grant с иной версией политики:
        // если бы вернулась первая строка истории, версия была бы 2026-01-01.
        $this->assertSame('2026-07-01', $second['policy_version']);
    }

    public function test_an_anonymous_consent_of_the_newsletter_is_not_listed(): void
    {
        $user = $this->plainUser();

        // Анонимное согласие рассылки: субъект — anonymous_id, НЕ аккаунт.
        // Его принадлежность держится на совпадении адреса, а не аутентификации —
        // в список владельца аккаунта оно попасть не должно.
        DB::table('consents')->insert([
            'user_id' => null,
            'anonymous_id' => (string) Str::uuid(),
            'consent_type' => 'newsletter_marketing',
            'status' => 'granted',
            'policy_version' => '2026-10-16',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/api/v1/me/consents');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /* ── отзыв: запись новой строки ──────────────────────────────────────── */

    public function test_withdrawal_writes_a_new_row_and_keeps_the_ledger(): void
    {
        $user = $this->plainUser('withdrawer@example.com');

        $this->seedConsent($user->id, 'marketing', 'granted', now()->subDay(), '2026-07-01');

        $response = $this->actingAs($user, 'api')
            ->withHeader('User-Agent', 'UA-Test/1.0')
            ->postJson('/api/v1/me/consents/marketing/withdraw');

        $response->assertOk();
        $response->assertJsonPath('data.written', true);
        $response->assertJsonPath('data.consent_type', 'marketing');
        $response->assertJsonPath('data.status', 'withdrawn');
        $this->assertNotNull($response->json('data.withdrawn_at'));

        // Журнал: одна granted-строка НЕ изменялась + одна строка отзыва.
        $rows = DB::table('consents')
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('granted', $rows[0]->status);
        $this->assertNull($rows[0]->withdrawn_at);

        $withdrawn = $rows[1];
        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertNotNull($withdrawn->withdrawn_at);

        // Момент отзыва = момент фиксации строки отзыва (юридически одно событие,
        // как у рассылки): извлечённый withdrawn_at не старше created_at.
        $this->assertTrue(
            Carbon::parse((string) $withdrawn->withdrawn_at)->equalTo(Carbon::parse((string) $withdrawn->created_at)),
            'Строка отзыва: withdrawn_at должен равняться created_at'
        );

        // Версия политики наследуется от отзываемого согласия: КЛИЕНТ подписался
        // на «2026-07-01», и отзыв должен опираться на ту же бумагу.
        $this->assertSame('2026-07-01', $withdrawn->policy_version);

        // IP записан УПАКОВАННЫМИ байтами (`binary(16)`), а не строкой —
        // прочтение идёт тем же обменником, что хранитель (PackedIp).
        $this->assertSame('127.0.0.1', PackedIp::unpack($withdrawn->ip_address));

        $this->assertSame('UA-Test/1.0', $withdrawn->user_agent);
    }

    /* ── отзыв уже отозванного ───────────────────────────────────────────── */

    public function test_re_withdrawing_returns_the_same_state_without_a_new_row(): void
    {
        $user = $this->plainUser();

        $this->seedConsent($user->id, 'marketing', 'granted', now()->subDays(2), '2026-07-01');

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/me/consents/marketing/withdraw')
            ->assertOk();

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/me/consents/marketing/withdraw')
            ->assertOk();

        // Строк ДВЕ (grant + withdraw), не три: повторный отзыв не пишет
        // третью строку — пустое действие не должно выглядеть как активность.
        $rows = DB::table('consents')->where('user_id', $user->id)->get();
        $this->assertCount(2, $rows);
        $this->assertSame(1, collect($rows)->where('status', 'withdrawn')->count());
    }

    /* ── ошибки адресации и состояния ────────────────────────────────────── */

    public function test_withdrawing_an_unknown_consent_type_is_404(): void
    {
        $user = $this->plainUser();

        // ДРУГАЯ цель есть, отзываемой нет: 404 говорит о цели, а не о пользователе.
        $this->seedConsent($user->id, 'analytics', 'granted', now(), '2026-07-01');

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v1/me/consents/marketing/withdraw');

        $response->assertNotFound();
        $this->assertSame('CONSENT_NOT_FOUND', $response->json('error.code'));
    }

    public function test_withdrawing_an_expired_consent_is_409(): void
    {
        $user = $this->plainUser();

        // Согласие истекло само: отзывать нечего — конфликт состояния, а не 404
        // (цель существует, и витрина вправе объяснить, чем она объясняется).
        $this->seedConsent($user->id, 'profiling', 'expired', now()->subYear(), '2026-01-01');

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v1/me/consents/profiling/withdraw');

        $response->assertStatus(409);
        $this->assertSame('CONSENT_NOT_WITHDRAWABLE', $response->json('error.code'));

        // И записи тоже не появилось: 409 — ответ, а не пассивное действие.
        $rows = DB::table('consents')->where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
    }

    /* ── история: последняя строка решает ────────────────────────────────── */

    public function test_a_re_granted_history_is_withdrawable_again(): void
    {
        $user = $this->plainUser();

        // grant → withdrawn → grant: последнее состояние — granted, значит цель
        // активна и отзывается. Отзыв доказывает, что чтение идёт newest-first,
        // а не «первая попавшаяся строка».
        $this->seedConsent($user->id, 'email_offers', 'granted', now()->subDays(3), '2026-01-01');
        $this->seedConsent($user->id, 'email_offers', 'withdrawn', now()->subDays(2), '2026-01-01');
        $this->seedConsent($user->id, 'email_offers', 'granted', now()->subDay(), '2026-07-01');

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v1/me/consents/email_offers/withdraw');

        $response->assertOk();
        $response->assertJsonPath('data.written', true);

        // Список ПОСЛЕ отзыва: последняя строка — свежий отзыв, статус withdrawn.
        $list = $this->actingAs($user, 'api')->getJson('/api/v1/me/consents');
        $list->assertOk();
        $list->assertJsonPath('data.0.status', 'withdrawn');
        $list->assertJsonCount(1, 'data');
    }

    /* ── фикстуры ────────────────────────────────────────────────────────── */

    /**
     * Строка согласия в журнал. Субъект здесь всегда аккаунт (`user_id`);
     * анонимные воспроизводятся вручную там, где проверяется их невидимость.
     */
    private function seedConsent(int $userId, string $type, string $status, CarbonInterface $moment, string $policyVersion): void
    {
        DB::table('consents')->insert([
            'user_id' => $userId,
            'anonymous_id' => null,
            'consent_type' => $type,
            'status' => $status,
            'withdrawn_at' => $status === 'withdrawn' ? $moment : null,
            'policy_version' => $policyVersion,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => $moment,
        ]);
    }

    /**
     * Покупатель без ролей: `auth:api` достаточно для всего флоу согласий —
     * владелец только из Bearer-токена, admin здесь не задействован.
     */
    private function plainUser(string $email = 'buyer@example.com'): User
    {
        $now = now();

        $id = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'password' => bcrypt('secret-password'),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::query()->findOrFail($id);
    }
}
