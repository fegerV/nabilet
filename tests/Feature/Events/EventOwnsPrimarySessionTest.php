<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Nabilet\Tests\Support\StaffAuth;
use Tests\TestCase;

/**
 * Мероприятие владеет датой/площадкой; сеанс — скрытая техническая тень.
 *
 * Проверяем, что при создании мероприятия с датой и площадкой автоматически
 * создаётся основной сеанс с инвентарём, и что правка даты не создаёт копию.
 */
final class EventOwnsPrimarySessionTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;
    use StaffAuth;

    public function test_event_creation_creates_one_hidden_primary_session(): void
    {
        [$organizationId, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 2);
        $legacy = DB::table('sessions')->where('id', $sessionId)->first();
        $this->actingAsStaff($organizationId);

        // Свежая схема через API: у фикстуры геометрия намеренно расходится с
        // JSON, и повторная генерация инвентаря её отвергает.
        $hallPublicId = (string) DB::table('halls')->where('id', $legacy->hall_id)->value('public_id');
        $seats = [];
        for ($i = 1; $i <= 2; $i++) {
            $seats[] = ['id' => "seat-$i", 'row' => 1, 'number' => $i, 'x' => $i * 24, 'y' => 20];
        }
        $draft = $this->postJson("/api/v1/halls/{$hallPublicId}/schema-versions/draft", [
            'payload' => [
                'canvas' => ['width' => 900, 'height' => 520],
                'sectors' => [[
                    'name' => 'Партер',
                    'priceMinor' => 500000,
                    'seats' => $seats,
                ]],
            ],
        ])->assertSuccessful();
        $schemaId = (int) $draft->json('data.id');
        $this->postJson("/api/v1/halls/{$hallPublicId}/schema-versions/{$schemaId}/publish", ['base_revision' => 1])->assertSuccessful();

        $response = $this->postJson('/api/v1/events', [
            'title' => 'Новый концерт',
            'slug' => 'novyj-koncert-' . uniqid(),
            'starts_at' => '2026-12-25 19:00:00',
            'venue_id' => $legacy->venue_id,
            'schema_version_id' => $schemaId,
        ])->assertCreated();

        $eventId = (int) $response->json('data.id');
        $event = DB::table('events')->where('id', $eventId)->first();

        self::assertNotNull($event->primary_session_id);
        self::assertSame('2026-12-25 19:00:00.000000', $event->starts_at);
        self::assertSame((string) $legacy->venue_id, (string) $event->venue_id);

        $primary = DB::table('sessions')->where('id', $event->primary_session_id)->first();
        self::assertNotNull($primary);
        self::assertSame($eventId, (int) $primary->event_id);
        self::assertSame((string) $schemaId, (string) $primary->schema_version_id);

        // Повторная правка даты не создаёт второй сеанс.
        $this->putJson('/api/v1/events/' . $eventId, [
            'title' => 'Новый концерт (обновлён)',
            'starts_at' => '2026-12-26 20:00:00',
        ])->assertOk();

        self::assertSame(
            1,
            (int) DB::table('sessions')->where('event_id', $eventId)->count(),
        );
        self::assertSame(
            '2026-12-26 20:00:00.000000',
            DB::table('sessions')->where('id', $event->primary_session_id)->value('starts_at'),
        );
    }
}
