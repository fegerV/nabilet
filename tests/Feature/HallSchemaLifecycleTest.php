<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Venues\Halls\Http\Resources\SchemaVersionResource;
use Nabilet\Modules\Venues\Halls\Services\HallService;
use Illuminate\Database\QueryException;
use Nabilet\Modules\Venues\Models\Hall;
use Tests\TestCase;

final class HallSchemaLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private Hall $hall;

    private HallService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $organizationId = DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Hall lifecycle test ' . Str::random(10),
            'slug' => 'hall-lifecycle-' . Str::lower(Str::random(12)),
            'status' => 'active',
            'settings_json' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $venueId = DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'Lifecycle venue',
            'slug' => 'lifecycle-' . Str::lower(Str::random(12)),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $hallId = DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $venueId,
            'name' => 'Lifecycle hall',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->hall = Hall::query()->findOrFail($hallId);
        $this->service = app(HallService::class);
    }

    public function test_stale_revision_is_rejected_after_successful_draft_update(): void
    {
        $firstPayload = $this->sellableSchema(1000);
        $draft = $this->service->createSchemaDraft($this->hall, $firstPayload, 1);

        self::assertSame(1, (int) $draft->revision);

        $updated = $this->service->createSchemaDraft(
            $this->hall,
            $this->sellableSchema(2000),
            1,
            (int) $draft->id,
            1,
        );
        self::assertSame(2, (int) $updated->revision);

        try {
            $this->service->createSchemaDraft(
                $this->hall,
                $this->sellableSchema(3000),
                1,
                (int) $draft->id,
                1,
            );
            self::fail('A stale base revision must not overwrite a newer draft.');
        } catch (ConflictError $error) {
            self::assertSame('SCHEMA_DRAFT_CONFLICT', $error->errorCode);
            self::assertSame(2, $error->context['current_revision']);
        }

        self::assertEquals($this->sellableSchema(2000), $updated->fresh()->schema_json);
    }

    public function test_republishing_archives_the_previous_version_atomically(): void
    {
        $first = $this->service->createSchemaDraft($this->hall, $this->sellableSchema(1000), 1);
        $this->service->publishSchemaVersion((int) $first->id, 1, (string) $this->hall->public_id);

        $second = $this->service->createSchemaDraft($this->hall, $this->sellableSchema(2000), 1);
        $published = $this->service->publishSchemaVersion((int) $second->id, 1, (string) $this->hall->public_id);

        self::assertSame('published', $published->status);
        self::assertSame('archived', $first->fresh()->status);
        self::assertSame(1, $this->hall->schemaVersions()->where('status', 'published')->count());
        self::assertSame(2, (int) $published->version);
    }

    public function test_archive_is_scoped_to_the_hall_and_requires_a_published_version(): void
    {
        $draft = $this->service->createSchemaDraft($this->hall, $this->sellableSchema(1000), 1);
        try {
            $this->service->archiveSchemaVersion((int) $draft->id, (string) Str::ulid()->toBase32());
            self::fail('A version must not be archivable through another hall scope.');
        } catch (NotFoundError) {
            self::assertSame('draft', $draft->fresh()->status);
        }

        try {
            $this->service->archiveSchemaVersion((int) $draft->id, (string) $this->hall->public_id);
            self::fail('A draft must not be archived.');
        } catch (ConflictError $error) {
            self::assertSame('SCHEMA_VERSION_NOT_PUBLISHED', $error->errorCode);
        }
    }

    public function test_database_trigger_enforces_revision_and_frozen_lifecycle_transitions(): void
    {
        $draft = $this->service->createSchemaDraft($this->hall, $this->sellableSchema(1000), 1);

        try {
            DB::table('hall_schema_versions')->where('id', $draft->id)->update(['revision' => 4]);
            self::fail('A draft revision cannot jump by more than one.');
        } catch (QueryException) {
            self::assertSame(1, (int) $draft->fresh()->revision);
        }

        DB::table('hall_schema_versions')->where('id', $draft->id)->update([
            'schema_json' => json_encode($this->sellableSchema(1500), JSON_THROW_ON_ERROR),
            'revision' => 2,
        ]);
        self::assertSame(2, (int) $draft->fresh()->revision);

        foreach (['archived', 'published'] as $invalidDraftStatus) {
            try {
                DB::table('hall_schema_versions')->where('id', $draft->id)->update(['status' => $invalidDraftStatus]);
                self::fail("A draft must not transition directly to {$invalidDraftStatus} through SQL.");
            } catch (QueryException) {
                self::assertSame('draft', $draft->fresh()->status);
            }
        }

        $this->service->publishSchemaVersion((int) $draft->id, 1, (string) $this->hall->public_id);
        foreach ([['status' => 'draft'], ['schema_json' => json_encode($this->sellableSchema(3000), JSON_THROW_ON_ERROR)]] as $invalidPublishedUpdate) {
            try {
                DB::table('hall_schema_versions')->where('id', $draft->id)->update($invalidPublishedUpdate);
                self::fail('A published version cannot be downgraded or have its geometry rewritten through SQL.');
            } catch (QueryException) {
                self::assertSame('published', $draft->fresh()->status);
            }
        }

        DB::table('hall_schema_versions')->where('id', $draft->id)->update(['status' => 'archived']);
        self::assertSame('archived', $draft->fresh()->status);

        try {
            DB::table('hall_schema_versions')->where('id', $draft->id)->update(['status' => 'published']);
            self::fail('An archived version must not be restored through SQL.');
        } catch (QueryException) {
            self::assertSame('archived', $draft->fresh()->status);
        }

        try {
            DB::table('hall_schema_versions')->where('id', $draft->id)->delete();
            self::fail('An archived version must not be deleted through SQL.');
        } catch (QueryException) {
            self::assertSame('archived', $draft->fresh()->status);
        }
    }

    public function test_malformed_schema_collections_are_rejected_without_runtime_warnings(): void
    {
        $payloads = [
            ['sectors' => 'not-an-array'],
            ['staticObjects' => 'not-an-array'],
            ['canvas' => 'not-an-object'],
            ['sectors' => [['name' => 'Broken', 'seats' => 'not-an-array']]],
            ['sectors' => [['name' => 'Broken', 'rows' => ['not-a-row-object']]]],
        ];

        foreach ($payloads as $payload) {
            try {
                $this->service->createSchemaDraft($this->hall, $payload, 1);
                self::fail('Malformed schema collections must be rejected.');
            } catch (ValidationError) {
                self::assertTrue(true);
            }
        }
    }

    public function test_sellable_standing_zone_requires_and_preserves_its_price(): void
    {
        $payload = [
            'canvas' => ['width' => 900, 'height' => 520],
            'sectors' => [],
            'staticObjects' => [[
                'id' => 'standing-1',
                'kind' => 'standing',
                'capacity' => 12,
                'priceMinor' => 275000,
                'text' => 'Фан-зона',
            ]],
        ];
        $draft = $this->service->createSchemaDraft($this->hall, $payload, 1);
        $inventorySchema = $draft->toInventoryFormat();

        self::assertTrue(HallService::payloadHasSeats($payload));
        self::assertSame('standing', $inventorySchema[0]['type']);
        self::assertSame(275000, $inventorySchema[0]['rows'][0]['price_amount']);

        $wrappedPayload = $payload;
        $wrappedPayload['sectors'] = [[
            'name' => 'Фан-зона',
            'type' => 'seated',
            'priceMinor' => 100000,
            'seats' => [],
        ]];
        $wrappedDraft = $this->service->createSchemaDraft(
            $this->hall,
            $wrappedPayload,
            1,
            (int) $draft->id,
            (int) $draft->revision,
        );
        $wrappedInventory = $wrappedDraft->toInventoryFormat();
        self::assertSame(275000, $wrappedInventory[0]['rows'][0]['price_amount']);

        $invalidCapacity = $payload;
        $invalidCapacity['staticObjects'][0]['capacity'] = '12';
        try {
            $this->service->createSchemaDraft($this->hall, $invalidCapacity, 1);
            self::fail('A standing-zone capacity must be a positive JSON integer.');
        } catch (ValidationError) {
            self::assertTrue(true);
        }

        unset($payload['staticObjects'][0]['priceMinor']);
        try {
            $this->service->createSchemaDraft($this->hall, $payload, 1);
            self::fail('A sellable standing zone must not silently become free inventory.');
        } catch (ValidationError) {
            self::assertTrue(true);
        }
    }

    public function test_legacy_draft_without_revision_uses_revision_one_then_advances(): void
    {
        $legacy = $this->hall->schemaVersions()->create([
            'version' => 1,
            'revision' => null,
            'schema_json' => $this->sellableSchema(1000),
            'status' => 'draft',
        ]);

        self::assertNull($legacy->revision);
        self::assertSame(1, (int) (new SchemaVersionResource($legacy))->toArray(request())['revision']);

        $updated = $this->service->createSchemaDraft(
            $this->hall,
            $this->sellableSchema(2000),
            1,
            (int) $legacy->id,
            1,
        );

        self::assertSame(2, (int) $updated->revision);
    }

    public function test_hall_with_published_schema_cannot_be_deleted_through_service_or_cascade(): void
    {
        $draft = $this->service->createSchemaDraft($this->hall, $this->sellableSchema(1000), 1);
        $this->service->publishSchemaVersion((int) $draft->id, 1, (string) $this->hall->public_id);

        try {
            $this->service->deleteHall($this->hall);
            self::fail('The service must refuse to delete a hall with a frozen schema.');
        } catch (ConflictError $error) {
            self::assertSame('HALL_HAS_FROZEN_SCHEMA_VERSIONS', $error->errorCode);
        }

        try {
            DB::table('hall_schema_versions')->where('id', $draft->id)->delete();
            self::fail('The database must refuse a direct delete of a published schema version.');
        } catch (QueryException) {
            self::assertSame('published', $draft->fresh()->status);
        }

        try {
            DB::table('halls')->where('id', $this->hall->id)->delete();
            self::fail('The database must refuse a hall delete that would cascade-delete a published version.');
        } catch (QueryException) {
            self::assertTrue(Hall::query()->whereKey($this->hall->id)->exists());
            self::assertSame('published', $draft->fresh()->status);
        }
    }

    public function test_hall_with_only_drafts_can_be_deleted(): void
    {
        $draft = $this->service->createSchemaDraft($this->hall, $this->sellableSchema(1000), 1);

        self::assertTrue($this->service->deleteHall($this->hall));
        self::assertFalse(Hall::query()->whereKey($this->hall->id)->exists());
        self::assertDatabaseMissing('hall_schema_versions', ['id' => $draft->id]);
    }

    public function test_duplicate_ids_numbers_and_overlapping_editor_seats_are_rejected(): void
    {
        $duplicate = $this->sellableSchema(1000);
        $duplicate['sectors'][0]['seats'][] = [
            'id' => 'seat-1',
            'row' => 1,
            'number' => 1,
            'x' => 100,
            'y' => 100,
        ];

        try {
            $this->service->createSchemaDraft($this->hall, $duplicate, 1);
            self::fail('Duplicate seat identifiers and numbering must be rejected.');
        } catch (ValidationError) {
            self::assertTrue(true);
        }

        $overlap = $this->sellableSchema(1000);
        $overlap['sectors'][] = [
            'name' => 'Second sector',
            'priceMinor' => 1000,
            'seats' => [[
                'id' => 'seat-2',
                'row' => 1,
                'number' => 1,
                'x' => 32,
                'y' => 32,
            ]],
        ];

        try {
            $this->service->createSchemaDraft($this->hall, $overlap, 1);
            self::fail('Overlapping seat geometry must be rejected independently of duplicate ids/numbers.');
        } catch (ValidationError) {
            self::assertTrue(true);
        }
    }

    public function test_sellable_seats_require_a_price_in_both_supported_payload_formats(): void
    {
        $unpricedEditorSchema = $this->sellableSchema(1000);
        unset($unpricedEditorSchema['sectors'][0]['priceMinor']);
        try {
            $this->service->createSchemaDraft($this->hall, $unpricedEditorSchema, 1);
            self::fail('Editor seats without a price would become zero-priced inventory.');
        } catch (ValidationError) {
            self::assertTrue(true);
        }

        $legacySchema = $this->sellableSchema(1000);
        $legacySchema['sectors'][0]['price'] = $legacySchema['sectors'][0]['priceMinor'];
        unset($legacySchema['sectors'][0]['priceMinor']);
        $legacyDraft = $this->service->createSchemaDraft($this->hall, $legacySchema, 1);
        self::assertSame(1000, $legacyDraft->toInventoryFormat()[0]['rows'][0]['price_amount']);

        $unpricedRowsSchema = [
            'sectors' => [[
                'name' => 'Imported',
                'rows' => [[
                    'number' => '1',
                    'seats' => [['number' => '1', 'type' => 'standard']],
                ]],
            ]],
        ];
        try {
            $this->service->createSchemaDraft($this->hall, $unpricedRowsSchema, 1);
            self::fail('Imported rows without price_amount would become zero-priced inventory.');
        } catch (ValidationError) {
            self::assertTrue(true);
        }
    }

    /** @return array<string, mixed> */
    private function sellableSchema(int $priceMinor): array
    {
        return [
            'canvas' => ['width' => 900, 'height' => 520],
            'sectors' => [[
                'name' => 'Main',
                'priceMinor' => $priceMinor,
                'seats' => [[
                    'id' => 'seat-1',
                    'row' => 1,
                    'number' => 1,
                    'x' => 20,
                    'y' => 20,
                ]],
            ]],
        ];
    }
}
