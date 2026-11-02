<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Tests\Support\StaffAuth;
use Tests\TestCase;

/**
 * Переводы редакционной страницы CMS (контракт openapi.yaml:2426-2468,
 * тег Translations).
 *
 * До этого шага пути контракта отвечали 404, а колонки `page_translations`
 * (включая `slug` с индексом `idx_page_translations_locale_slug`) не имели
 * ни писателя, ни читателя. Контракт полосатый: список — витрина
 * (без auth, видимость = та же пара `status` + `published_at`, что в
 * `PageService::forSlug`), upsert — под `auth:api` + `admin`.
 *
 * ИЗОЛЯЦИЯ. `pages` — nullable-исключение скоупа (NULL = «общая строка»
 * платформы), поэтому binding'ом владельца не решить: `PageTranslation-
 * Controller` делает lookup по unguessable `public_id` и проверяет владельца
 * через `OrganizationContext::assertCanAccess` — общая страница (NULL) и
 * чужая отвечают 404 одинаково (IDOR).
 */
final class PageTranslationsTest extends TestCase
{
    use RefreshDatabase;
    use StaffAuth;

    public function test_public_list_returns_translations_of_a_published_page(): void
    {
        [$pagePublicId, $pageId, $pageOrgId] = $this->seedPage();

        DB::table('page_translations')->insert([
            ['page_id' => $pageId, 'organization_id' => $pageOrgId, 'locale' => 'ru', 'title' => 'Возврат билетов', 'slug' => 'vozvrat-ru'],
            ['page_id' => $pageId, 'organization_id' => $pageOrgId, 'locale' => 'en', 'title' => 'Ticket refunds', 'slug' => 'refunds-en'],
        ]);

        $response = $this->getJson("/api/v1/pages/{$pagePublicId}/translations")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Список отсортирован по locale в контроллере — порядок ответа стабилен.
        $locales = collect($response->json('data'))->pluck('locale')->all();
        $this->assertSame(['en', 'ru'], $locales);
    }

    public function test_public_list_answers_404_for_draft_and_unknown_page(): void
    {
        [$draftPublicId] = $this->seedPage(status: 'draft', publishedAtProvided: false);

        // Переводы черновика неотличимы от переводов несуществующей страницы:
        // иначе публичный список стал бы окном в ещё не опубликованный контент.
        $this->getJson("/api/v1/pages/{$draftPublicId}/translations")->assertNotFound();
        $this->getJson('/api/v1/pages/' . Str::ulid()->toBase32() . '/translations')->assertNotFound();
    }

    public function test_upsert_creates_then_replaces_the_same_row(): void
    {
        $organizationId = $this->seedOrganization();
        [$pagePublicId] = $this->seedPage(organizationId: $organizationId);
        $this->actingAsStaff($organizationId);

        $this->putJson("/api/v1/pages/{$pagePublicId}/translations/en", [
            'title' => 'Ticket refunds',
            'slug' => 'refunds-en',
            'content' => '<p>Refund policy</p>',
            'seo_title' => 'Refunds',
        ])->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.title', 'Ticket refunds')
            ->assertJsonPath('data.slug', 'refunds-en');

        $this->assertSame(1, DB::table('page_translations')->count());

        // Замена: PUT фиксирует полный образ перевода — поле, не присланное
        // в запросе, стирается.
        $this->putJson("/api/v1/pages/{$pagePublicId}/translations/en", [
            'title' => 'Refunds and exchanges',
        ])->assertOk()->assertJsonPath('data.title', 'Refunds and exchanges');

        $row = DB::table('page_translations')->first();
        $this->assertSame('Refunds and exchanges', $row->title);
        $this->assertSame(null, $row->slug, 'не присланные nullable-поля стираются');
        $this->assertSame($organizationId, (int) $row->organization_id, 'тенант наследуется от страницы');
    }

    public function test_upsert_requires_authentication(): void
    {
        [$pagePublicId] = $this->seedPage();

        $this->putJson("/api/v1/pages/{$pagePublicId}/translations/en", ['title' => 'X'])
            ->assertUnauthorized();

        $this->assertSame(0, DB::table('page_translations')->count());
    }

    public function test_upsert_answers_404_for_foreign_shared_and_unknown_page(): void
    {
        $organizationId = $this->seedOrganization();
        [$pagePublicId] = $this->seedPage(organizationId: $organizationId);

        $otherOrganizationId = $this->seedOrganization('Соседняя организация');
        [$foreignPublicId] = $this->seedPage(organizationId: $otherOrganizationId);
        [$sharedPublicId] = $this->seedPage(shared: true);

        $this->actingAsStaff($organizationId);

        // Чужая страница, «общая строка» платформы (organization_id NULL) и
        // несуществующая отвечают одним и тем же 404.
        $this->putJson("/api/v1/pages/{$foreignPublicId}/translations/en", ['title' => 'Hacked'])
            ->assertNotFound();
        $this->putJson("/api/v1/pages/{$sharedPublicId}/translations/en", ['title' => 'Hacked'])
            ->assertNotFound();
        $this->putJson('/api/v1/pages/' . Str::ulid()->toBase32() . '/translations/en', ['title' => 'Ghost'])
            ->assertNotFound();

        $this->assertSame(0, DB::table('page_translations')->count());
    }

    public function test_upsert_answers_422_when_slug_is_taken_in_the_locale(): void
    {
        $organizationId = $this->seedOrganization();
        [$pagePublicId] = $this->seedPage(organizationId: $organizationId, slug: 'refunds');
        [$otherPublicId] = $this->seedPage(organizationId: $organizationId, slug: 'exchanges');
        $this->actingAsStaff($organizationId);

        $this->putJson("/api/v1/pages/{$pagePublicId}/translations/en", ['slug' => 'refunds-en'])
            ->assertOk();

        // Локализованный адрес должен быть разрешим однозначно: в одном языке
        // две страницы не могут претендовать на один slug.
        $this->putJson("/api/v1/pages/{$otherPublicId}/translations/en", ['slug' => 'refunds-en'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.slug.0', 'This slug is already used by another page in this locale.');

        // Тот же slug в ДРУГОМ языке — свободен: адресные пространства
        // локалей не пересекаются.
        $this->putJson("/api/v1/pages/{$otherPublicId}/translations/de", ['slug' => 'refunds-en'])
            ->assertOk();

        // Свой slug при замене перевода — не конфликт: страница меняет
        // собственную строку.
        $this->putJson("/api/v1/pages/{$pagePublicId}/translations/en", ['slug' => 'refunds-en'])
            ->assertOk();
    }

    public function test_upsert_answers_422_for_unsupported_locale(): void
    {
        $organizationId = $this->seedOrganization();
        [$pagePublicId] = $this->seedPage(organizationId: $organizationId);
        $this->actingAsStaff($organizationId);

        $this->putJson("/api/v1/pages/{$pagePublicId}/translations/ru_RU", ['title' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.locale.0', 'The locale must be a language tag like "ru", "en" or "pt-br".');
    }

    private function seedOrganization(string $name = 'Сургут-Концерт'): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => 'org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Страница CMS: published по умолчанию (пара ключей
     * `status` + `published_at` согласована с `PageService::forSlug`).
     * Без `organizationId` создаёт собственную организацию; `$shared` —
     * «общая строка» платформы: `organization_id` NULL, которую не написать
     * через админ-путь (см. тест изоляции).
     *
     * @return array{0: string, 1: int, 2: int} [page public_id, page id, organization id]
     */
    private function seedPage(
        string $slug = 'refund-policy',
        string $status = 'published',
        bool $publishedAtProvided = true,
        bool $shared = false,
        ?int $organizationId = null,
    ): array {
        $now = now()->toDateTimeString();
        $publicId = (string) Str::ulid()->toBase32();
        $organizationId = $organizationId ?? $this->seedOrganization();

        $pageId = (int) DB::table('pages')->insertGetId([
            'public_id' => $publicId,
            'organization_id' => $shared ? null : $organizationId,
            'title' => 'Возврат билетов',
            'slug' => $slug,
            'content' => '<p>Условия возврата билетов.</p>',
            'status' => $status,
            'published_at' => $publishedAtProvided ? now()->subDay()->toDateTimeString() : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$publicId, $pageId, $organizationId];
    }
}
