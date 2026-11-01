<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Core\Tenancy\TenantResolver;
use Nabilet\Modules\Loyalty\Models\EventFavorite;
use Tests\TestCase;

/**
 * Запись организации по родителю (фаза 3 мультитенантности).
 *
 * Гостевой checkout и вебхук платёжного провайдера работают ВНЕ контекста
 * запроса: покупатель не аутентифицирован, а `processWebhook` идёт внутри
 * `withoutScope()`. Если бы `organization_id` заполнялся только из контекста,
 * такие строки остались бы NULL — а с NOT NULL (цель фазы 3) запись бы просто
 * падала. Поэтому организация выводится из родителя: избранное из события,
 * билет из заказа и т.д.
 *
 * Проверяется:
 *   - без контекста строка наследует организацию родителя;
 *   - в контексте той же организации — тоже наследует (и не падает);
 *   - контекст ЧУЖОЙ организации — отказ, а не запись не туда;
 *   - явный чужой `organization_id` при известном родителе — отказ;
 *   - неизвестная таблица не участвует в цепочке.
 */
final class TenantResolverWriteTest extends TestCase
{
    use RefreshDatabase;

    private function organization(string $name = 'Org'): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => 'org-' . Str::random(8),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function event(int $organizationId): int
    {
        return (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => 'Событие',
            'slug' => 'event-' . Str::random(8),
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(): int
    {
        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_without_context_the_row_inherits_the_parent_organization(): void
    {
        $orgId = $this->organization();
        $eventId = $this->event($orgId);
        $userId = $this->user();

        app(OrganizationContext::class)->clear();

        $favorite = EventFavorite::query()->create([
            'user_id' => $userId,
            'event_id' => $eventId,
        ]);

        $this->assertSame($orgId, (int) $favorite->organization_id);

        $stored = DB::table('event_favorites')->where('id', $favorite->id)->value('organization_id');
        $this->assertSame($orgId, (int) $stored);
    }

    public function test_context_does_not_override_a_parent_from_another_organization(): void
    {
        $parentOrg = $this->organization('Parent');
        $otherOrg = $this->organization('Other');
        $eventId = $this->event($parentOrg);
        $userId = $this->user();

        app(OrganizationContext::class)->set((string) $otherOrg);

        $this->expectException(NotFoundError::class);

        EventFavorite::query()->create([
            'user_id' => $userId,
            'event_id' => $eventId,
        ]);
    }

    public function test_an_explicit_foreign_organization_id_is_rejected(): void
    {
        $parentOrg = $this->organization('Parent');
        $otherOrg = $this->organization('Other');
        $eventId = $this->event($parentOrg);
        $userId = $this->user();

        app(OrganizationContext::class)->clear();

        $this->expectException(NotFoundError::class);

        // Массовое заполнение отбросило бы `organization_id` (его нет в
        // `$fillable`), поэтому явное значение ставим атрибутом — ровно так
        // попытался бы поступить код, обходящий защиту.
        $favorite = new EventFavorite([
            'user_id' => $userId,
            'event_id' => $eventId,
        ]);
        $favorite->organization_id = $otherOrg;
        $favorite->save();
    }

    public function test_context_of_the_same_organization_is_accepted(): void
    {
        $orgId = $this->organization();
        $eventId = $this->event($orgId);
        $userId = $this->user();

        app(OrganizationContext::class)->set((string) $orgId);

        $favorite = EventFavorite::query()->create([
            'user_id' => $userId,
            'event_id' => $eventId,
        ]);

        $this->assertSame($orgId, (int) $favorite->organization_id);
    }

    public function test_unknown_table_is_not_part_of_the_chain(): void
    {
        $this->assertFalse(TenantResolver::knows('users'));
        $this->assertTrue(TenantResolver::knows('event_favorites'));
        $this->assertNull(TenantResolver::fromAttributes('users', ['id' => 1]));
    }
}
