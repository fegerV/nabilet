<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Support\Sha256Hex;
use Nabilet\Modules\Embed\Domain\EmbedScope;
use Nabilet\Modules\Embed\Services\EmbedTokenIssuer;
use Nabilet\Tests\Support\EmbedFixtures;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Nabilet\Tests\Support\StaffAuth;
use Tests\TestCase;

/**
 * Провижининг встраивания: выпуск embed-ключа и whitelist доменов.
 *
 * ЧТО БЫЛО. `EmbedSecurityTest` доказывает, что гейт §18 работает, — и он
 * действительно работал. Но включить встраивание было НЕЧЕМ: `api_keys` со
 * скоупом `embed` и `embed_domains` не писал ни один код приложения, поэтому
 * каждый запрос к `/api/v1/embed/*` получал 403, а исправить это настройкой
 * было невозможно. Обе таблицы заполняли только тесты, через `DB::table()`.
 *
 * ПОЧЕМУ ГЛАВНЫЙ ТЕСТ ФАЙЛА — СКВОЗНОЙ. Проверить по отдельности «ключ создался»
 * и «гейт отказывает» недостаточно: именно их сочетание и было сломано. Токен,
 * выпущенный через админку, обязан быть принят тем самым гейтом, который до сих
 * пор отвергал всё, — иначе провижининг бесполезен, даже если обе половины
 * зелены по отдельности.
 */
class EmbedProvisioningTest extends TestCase
{
    use EmbedFixtures;
    use RefreshDatabase;
    use SellableSeatFixtures;
    use StaffAuth;

    /** @return array{0: int, 1: int} [organizationId, eventId] */
    private function seedPublishedEvent(): array
    {
        return $this->seedSellableSeat(available: 10);
    }

    /** @return array{token: string, keyId: int} */
    private function issueKey(int $organizationId, string $name = 'Сайт партнёра'): array
    {
        $response = $this->postJson('/api/v1/admin/embed/keys', [
            'organization_id' => $organizationId,
            'name' => $name,
            'expires_at' => now()->addYear()->toIso8601String(),
        ])->assertStatus(201);

        return [
            'token' => (string) $response->json('data.token'),
            'keyId' => (int) $response->json('data.key.id'),
        ];
    }

    /** @return int id записи whitelist */
    private function addDomain(int $organizationId, string $domain): int
    {
        return (int) $this->postJson('/api/v1/admin/embed/domains', [
            'organization_id' => $organizationId,
            'domain' => $domain,
        ])->assertStatus(201)->json('data.id');
    }

    // ── сквозной контур ─────────────────────────────────────────────────────

    /**
     * Токен, выпущенный через админку, действительно открывает витрину.
     *
     * ЭТО И ЕСТЬ ТО, ЧЕГО НЕ ХВАТАЛО. До провижининга этот запрос не мог
     * вернуть 200 ни при какой настройке: ключа неоткуда было взять.
     */
    public function test_a_key_issued_through_the_admin_api_unlocks_the_embed_surface(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        // Домен вносим полным адресом — так его копируют из строки браузера.
        $this->postJson('/api/v1/admin/embed/domains', [
            'organization_id' => $organizationId,
            'domain' => 'https://partner.example/afisha',
        ])->assertStatus(201)->assertJsonPath('data.domain', 'partner.example');

        ['token' => $token] = $this->issueKey($organizationId);

        $this->assertStringStartsWith(EmbedTokenIssuer::TOKEN_PREFIX, $token);

        $this->getJson(
            $this->embedUrl("/api/v1/embed/events/{$eventId}", $token),
            $this->embedHeaders()
        )->assertStatus(200);
    }

    public function test_an_organization_that_has_not_added_a_domain_still_refuses(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        ['token' => $token] = $this->issueKey($organizationId);

        // Ключ рабочий, но список доменов пуст — отказ по другой причине.
        $this->getJson(
            $this->embedUrl("/api/v1/embed/events/{$eventId}", $token),
            $this->embedHeaders()
        )->assertStatus(403)->assertJsonPath('error.code', 'EMBED_DOMAINS_NOT_CONFIGURED');
    }

    // ── выпуск ключа ────────────────────────────────────────────────────────

    public function test_the_token_is_stored_only_as_a_digest(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        ['token' => $token, 'keyId' => $keyId] = $this->issueKey($organizationId);

        $stored = (string) DB::table('api_keys')->where('id', $keyId)->value('key_hash');

        $this->assertSame(Sha256Hex::of($token), $stored);
        $this->assertNotSame($token, $stored, 'плейнтекста в базе быть не должно');
    }

    public function test_the_issued_key_carries_the_embed_scope(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        ['keyId' => $keyId] = $this->issueKey($organizationId);

        $scopes = json_decode((string) DB::table('api_keys')->where('id', $keyId)->value('scopes_json'), true);

        $this->assertSame(EmbedScope::all(), $scopes);
    }

    public function test_the_list_never_returns_the_token_or_its_digest(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        ['token' => $token, 'keyId' => $keyId] = $this->issueKey($organizationId);

        $body = (string) $this->getJson('/api/v1/admin/embed/keys')->assertOk()->getContent();

        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString(Sha256Hex::of($token), $body);
        $this->assertStringNotContainsString('key_hash', $body);

        // Список при этом не пустой — иначе проверка выше ничего не значит.
        $this->getJson('/api/v1/admin/embed/keys')
            ->assertOk()
            ->assertJsonFragment(['id' => $keyId]);
    }

    public function test_an_issue_without_an_expiry_is_refused(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        // Бессрочный ключ нельзя вывести из обращения — `ApiKeyPolicy` отказывает
        // такому выпуску, поэтому граница требует срок заранее.
        $this->postJson('/api/v1/admin/embed/keys', [
            'organization_id' => $organizationId,
            'name' => 'Бессрочный',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['expires_at']]]]);
    }

    public function test_an_expiry_in_the_past_is_refused(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        $this->postJson('/api/v1/admin/embed/keys', [
            'organization_id' => $organizationId,
            'name' => 'Просроченный',
            'expires_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['expires_at']]]]);
    }

    public function test_issuing_requires_an_authenticated_admin(): void
    {
        [$organizationId] = $this->seedPublishedEvent();

        // Без входа — отказ: раздача embed-токена даёт право продавать от имени
        // организации на чужом сайте.
        $this->postJson('/api/v1/admin/embed/keys', [
            'organization_id' => $organizationId,
            'name' => 'Аноним',
            'expires_at' => now()->addYear()->toIso8601String(),
        ])->assertStatus(401);

        $this->assertSame(0, DB::table('api_keys')->count());
    }

    // ── отзыв ───────────────────────────────────────────────────────────────

    public function test_a_revoked_key_stops_working(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        $this->addDomain($organizationId, 'partner.example');
        ['token' => $token, 'keyId' => $keyId] = $this->issueKey($organizationId);

        $url = $this->embedUrl("/api/v1/embed/events/{$eventId}", $token);

        $this->getJson($url, $this->embedHeaders())->assertStatus(200);

        $this->patchJson("/api/v1/admin/embed/keys/{$keyId}", ['revoked' => true])->assertOk();

        $this->getJson($url, $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_TOKEN_INVALID');
    }

    /**
     * Отзыв необратим: `false` отвергается.
     *
     * В `api_keys` нет истории, поэтому снятый `revoked_at` стёр бы сам факт
     * отзыва, и утёкший токен вернулся бы к жизни флажком в админке.
     */
    public function test_revocation_cannot_be_undone(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        ['keyId' => $keyId] = $this->issueKey($organizationId);

        $this->patchJson("/api/v1/admin/embed/keys/{$keyId}", ['revoked' => false])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['revoked']]]]);
    }

    public function test_revoking_an_unknown_key_is_a_404(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        $this->patchJson('/api/v1/admin/embed/keys/does-not-exist', ['revoked' => true])
            ->assertStatus(404);
    }

    // ── домены ──────────────────────────────────────────────────────────────

    public function test_a_bare_host_and_a_full_url_normalise_to_the_same_entry(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        $this->addDomain($organizationId, 'https://Partner.Example/afisha');

        $this->assertSame(
            'partner.example',
            (string) DB::table('embed_domains')->where('organization_id', $organizationId)->value('domain')
        );

        // Второй раз тот же сайт — конфликт, а не вторая строка.
        $this->postJson('/api/v1/admin/embed/domains', [
            'organization_id' => $organizationId,
            'domain' => 'partner.example',
        ])->assertStatus(409)->assertJsonPath('error.code', 'EMBED_DOMAIN_ALREADY_LISTED');

        $this->assertSame(1, DB::table('embed_domains')->where('organization_id', $organizationId)->count());
    }

    public function test_a_subdomain_entry_is_kept_with_its_leading_dot(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        $this->addDomain($organizationId, '.partner.example');

        $this->assertSame(
            '.partner.example',
            (string) DB::table('embed_domains')->where('organization_id', $organizationId)->value('domain')
        );
    }

    public function test_something_that_is_not_a_host_is_refused(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        $this->postJson('/api/v1/admin/embed/domains', [
            'organization_id' => $organizationId,
            'domain' => 'не сайт',
        ])->assertStatus(422)->assertJsonPath('error.code', 'EMBED_DOMAIN_INVALID');

        $this->assertSame(0, DB::table('embed_domains')->count());
    }

    public function test_a_disabled_domain_stops_admitting_the_site(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        $domainId = $this->addDomain($organizationId, 'partner.example');
        ['token' => $token] = $this->issueKey($organizationId);

        $url = $this->embedUrl("/api/v1/embed/events/{$eventId}", $token);

        $this->getJson($url, $this->embedHeaders())->assertStatus(200);

        $this->patchJson("/api/v1/admin/embed/domains/{$domainId}", ['active' => false])->assertOk();

        // Выключенный домен остаётся в списке, но перестаёт пускать: это разница
        // между «мы его выключили» и «мы его никогда не вносили».
        $this->getJson($url, $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_DOMAINS_NOT_CONFIGURED');
    }

    public function test_a_removed_domain_stops_admitting_the_site(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        $domainId = $this->addDomain($organizationId, 'partner.example');
        ['token' => $token] = $this->issueKey($organizationId);

        $url = $this->embedUrl("/api/v1/embed/events/{$eventId}", $token);

        $this->getJson($url, $this->embedHeaders())->assertStatus(200);

        $this->deleteJson("/api/v1/admin/embed/domains/{$domainId}")->assertOk();

        $this->assertSame(0, DB::table('embed_domains')->count());

        $this->getJson($url, $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_DOMAINS_NOT_CONFIGURED');
    }

    /**
     * Нечисловой идентификатор домена не доходит до контроллера.
     *
     * У `embed_domains` нет колонки `public_id`, поэтому `IdentifierLookup` умеет
     * разрешать только числовой id: нечисловое значение он отправил бы в
     * `where('public_id', …)` — в несуществующую колонку, то есть в 500
     * (`SQLSTATE[42S22]`). Защита — `whereNumber` на маршруте.
     *
     * ТОЧНЫЙ КОД ЗДЕСЬ НЕ ПРОВЕРЯЕТСЯ НАМЕРЕННО. Сегодня такой путь упирается в
     * `Route::fallback()` из `routes/web.php`, который зарегистрирован только для
     * GET, поэтому ответ — 405. Это глобальное поведение приложения для любого
     * несматченного не-GET пути, и оно изменится, если у домена появится GET по
     * id. Значимо одно: запрос НЕ дошёл до владельца разрешения идентификатора.
     */
    public function test_a_non_numeric_domain_identifier_never_reaches_the_controller(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        $this->addDomain($organizationId, 'partner.example');

        $status = $this->patchJson('/api/v1/admin/embed/domains/1abc', ['active' => false])->status();

        $this->assertGreaterThanOrEqual(400, $status, 'нечисловой идентификатор не должен разрешаться');
        $this->assertLessThan(500, $status, 'нечисловой идентификатор не должен доходить до SQL');
    }

    public function test_an_unknown_numeric_domain_is_a_404(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $this->actingAsStaff($organizationId);

        $this->patchJson('/api/v1/admin/embed/domains/999999', ['active' => false])
            ->assertStatus(404);
    }
}
