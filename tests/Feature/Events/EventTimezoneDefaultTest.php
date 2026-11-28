<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Events\Services\EventService;
use Tests\TestCase;

/**
 * Часовой пояс мастера установки должен наследоваться мероприятием и сеансом.
 *
 * До 1.16.3 events.timezone оставался NULL, а sessions.timezone получал UTC из
 * DEFAULT БД: выбранный владельцем пояс игнорировался на витрине и в билетах.
 */
final class EventTimezoneDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_created_event_uses_the_installation_timezone(): void
    {
        config(['app.timezone' => 'Asia/Yekaterinburg']);
        $now = now()->toDateTimeString();
        $orgId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'TZ Org',
            'slug' => 'tz-org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        app(OrganizationContext::class)->set((string) $orgId);

        $event = app(EventService::class)->create([
            'organization_id' => $orgId,
            'title' => 'Событие в местном времени',
            'slug' => 'tz-event-' . Str::random(6),
        ]);

        $this->assertSame(
            'Asia/Yekaterinburg',
            (string) DB::table('events')->where('id', $event->id)->value('timezone'),
        );

        // Явно заданный пояс не перетирается значением по умолчанию.
        $explicit = app(EventService::class)->create([
            'organization_id' => $orgId,
            'title' => 'Другой пояс',
            'slug' => 'tz-event-' . Str::random(6),
            'timezone' => 'Europe/Moscow',
        ]);
        $this->assertSame(
            'Europe/Moscow',
            (string) DB::table('events')->where('id', $explicit->id)->value('timezone'),
        );
    }
}
