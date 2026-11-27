<?php

declare(strict_types=1);

namespace Tests\Feature\Updater;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Updater\Services\UpdateApplier;
use Nabilet\Modules\Updater\Services\UpdateJournal;
use Nabilet\Modules\Updater\Services\UpdateStorage;
use Tests\TestCase;

/**
 * Надёжность self-update (аудит 2026-11-26): журнал операций, безопасный откат,
 * глобальная блокировка и актуальность аварийного скрипта.
 */
final class UpdaterReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private const UPDATE_ID = 990114;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        app(MaintenanceMode::class)->deactivate();
        $this->root = sys_get_temp_dir() . '/nabilet-updater-reliability-' . Str::random(10);
        $this->makeDir($this->root . '/app');
        $this->makeDir($this->root . '/public_html');
        $this->write('artisan', 'original artisan');
        $this->write('app/config.php', 'original config');
        $this->write('public_html/index.php', 'original entry');
    }

    protected function tearDown(): void
    {
        app(MaintenanceMode::class)->deactivate();
        $storage = app(UpdateStorage::class);
        $storage->deleteTree($storage->base(self::UPDATE_ID));
        $storage->deleteTree($this->root);
        parent::tearDown();
    }

    public function test_rollback_never_deletes_an_original_when_its_backup_is_missing(): void
    {
        $storage = app(UpdateStorage::class);
        $this->makeDir($storage->backup(self::UPDATE_ID));

        app(UpdateApplier::class)->rollback(self::UPDATE_ID, $this->root);

        $this->assertSame('original artisan', $this->read('artisan'));
        $this->assertSame('original config', $this->read('app/config.php'));
        $this->assertSame('original entry', $this->read('public_html/index.php'));
    }

    public function test_apply_writes_a_durable_journal_with_paths_checksums_and_status(): void
    {
        $storage = app(UpdateStorage::class);
        $staging = $storage->staging(self::UPDATE_ID);
        $this->makeDir($staging . '/app');
        $this->writeTo($staging, 'app/config.php', 'new config');

        app(UpdateApplier::class)->apply(self::UPDATE_ID, $this->root);

        $entries = app(UpdateJournal::class)->entries(self::UPDATE_ID);
        $this->assertNotEmpty($entries);
        $this->assertSame('begin', $entries[0]['op']);
        $swap = null;
        foreach ($entries as $entry) {
            if (($entry['op'] ?? null) === 'swap_dir'
                && ($entry['status'] ?? null) === 'done'
                && ($entry['path'] ?? null) === 'app') {
                $swap = $entry;
            }
        }
        $this->assertNotNull($swap, 'Успешная замена каталога должна быть подтверждена в журнале');
        $this->assertSame('app', $swap['path']);
        $this->assertTrue($swap['original_present']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $swap['checksum_after']);
        $before = null;
        foreach ($entries as $entry) {
            if (($entry['op'] ?? null) === 'backup_dir' && ($entry['path'] ?? null) === 'app') {
                $before = $entry;
            }
        }
        $this->assertNotNull($before, 'До замены должна быть записана контрольная сумма оригинала');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $before['checksum_before']);
        $this->assertStringContainsString('backup', (string) $swap['backup']);
    }

    public function test_rollback_restores_only_journal_entries_and_leaves_unrelated_files_untouched(): void
    {
        $storage = app(UpdateStorage::class);
        $journal = app(UpdateJournal::class);
        $backup = $storage->backup(self::UPDATE_ID) . '/app/config.php';
        $this->makeDir(dirname($backup));
        $this->writeTo(dirname($backup), 'config.php', 'original config');
        $this->write('app/config.php', 'changed config');
        $this->write('app/untouched.php', 'untouched local file');

        $journal->record(self::UPDATE_ID, 'swap_file', [
            'status' => 'done',
            'path' => 'app/config.php',
            'backup' => $backup,
            'original_present' => true,
        ]);

        app(UpdateApplier::class)->rollback(self::UPDATE_ID, $this->root);

        $this->assertSame('original config', $this->read('app/config.php'));
        $this->assertSame('untouched local file', $this->read('app/untouched.php'));
        $this->assertTrue($journal->completed(self::UPDATE_ID) === false);
    }

    public function test_journal_records_migration_and_completion_separately(): void
    {
        $journal = app(UpdateJournal::class);
        $this->assertFalse($journal->migrationApplied(self::UPDATE_ID));

        $journal->record(self::UPDATE_ID, 'migrate', ['status' => 'done', 'to_version' => '1.17.0']);
        $this->assertTrue($journal->migrationApplied(self::UPDATE_ID));
        $this->assertFalse($journal->completed(self::UPDATE_ID));

        $journal->record(self::UPDATE_ID, 'complete', ['status' => 'done', 'version' => '1.17.0']);
        $this->assertTrue($journal->completed(self::UPDATE_ID));
    }

    public function test_recovery_script_is_replaced_when_a_stale_copy_exists(): void
    {
        $storage = app(UpdateStorage::class);
        $target = $storage->recoveryScript();
        $this->makeDir(dirname($target));
        file_put_contents($target, "<?php // stale recovery\n");

        $storage->ensureRecoveryScript();

        $this->assertSame(
            hash_file('sha256', base_path('scripts/updater-recover.php')),
            hash_file('sha256', $target),
            'Старый аварийный скрипт должен заменяться новой версией'
        );
        @unlink($target);
    }

    public function test_unconfirmed_pending_operation_is_restored_from_actual_disk_state(): void
    {
        // Имитация SIGKILL после rename(), но до записи status=done.
        $storage = app(UpdateStorage::class);
        $journal = app(UpdateJournal::class);
        $backup = $storage->backup(self::UPDATE_ID) . '/app/config.php';
        $this->makeDir(dirname($backup));
        $this->writeTo(dirname($backup), 'config.php', 'original config');
        $this->write('app/config.php', 'new config');

        $journal->record(self::UPDATE_ID, 'swap_file', [
            'status' => 'pending',
            'path' => 'app/config.php',
            'backup' => $backup,
            'original_present' => true,
        ]);

        app(UpdateApplier::class)->rollback(self::UPDATE_ID, $this->root);

        $this->assertSame('original config', $this->read('app/config.php'));
    }

    public function test_migration_states_distinguish_started_partial_and_uncertain(): void
    {
        $journal = app(UpdateJournal::class);
        $this->assertSame('not_started', $journal->migrationState(self::UPDATE_ID));

        $journal->record(self::UPDATE_ID, 'migrate', ['status' => 'started']);
        $this->assertSame('started', $journal->migrationState(self::UPDATE_ID));
        $this->assertTrue($journal->migrationApplied(self::UPDATE_ID));

        $journal->record(self::UPDATE_ID, 'migrate', ['status' => 'uncertain']);
        $this->assertSame('uncertain', $journal->migrationState(self::UPDATE_ID));

        $journal->record(self::UPDATE_ID, 'migrate', ['status' => 'done']);
        $this->assertSame('completed', $journal->migrationState(self::UPDATE_ID));
    }

    public function test_rollback_is_blocked_when_migration_was_started(): void
    {
        $this->markTestSkippedIfNoOrchestrator();
        $id = (int) DB::table('system_updates')->insertGetId([
            'from_version' => '1.0.0', 'to_version' => '1.1.0',
            'source' => 'upload', 'status' => 'done', 'step' => 'verified',
            'artifact' => 'release.zip', 'artifact_sha256' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(UpdateJournal::class)->record($id, 'migrate', ['status' => 'started']);

        try {
            app(\Nabilet\Modules\Updater\Services\UpdateOrchestrator::class)->requestRollback($id);
            $this->fail('Откат после начала миграций должен быть заблокирован');
        } catch (\Nabilet\Core\Errors\ConflictError $e) {
            $this->assertSame('UPDATE_ROLLBACK_REQUIRES_DB_RESTORE', $e->errorCode);
            $this->assertSame('started', $e->context['migration_state'] ?? null);
        }
    }

    public function test_fresh_migrating_state_is_not_mistaken_for_an_interrupted_update(): void
    {
        $id = (int) DB::table('system_updates')->insertGetId([
            'from_version' => '1.16.0', 'to_version' => '1.16.1',
            'source' => 'upload', 'status' => 'migrating', 'step' => 'swap_done',
            'artifact' => 'release.zip', 'artifact_sha256' => str_repeat('b', 64),
            'locked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(\Nabilet\Modules\Updater\Services\UpdateOrchestrator::class)->tick();

        $row = DB::table('system_updates')->where('id', $id)->first();
        $this->assertSame('migrating', (string) $row->status);
        $this->assertNull($row->error);
    }

    private function markTestSkippedIfNoOrchestrator(): void
    {
        if (! class_exists(\Nabilet\Modules\Updater\Services\UpdateOrchestrator::class)) {
            $this->markTestSkipped('UpdateOrchestrator недоступен');
        }
    }

    public function test_global_lock_is_exclusive_and_non_blocking(): void
    {
        $storage = app(UpdateStorage::class);
        $outer = $storage->withGlobalLock(
            static fn (): string => ($storage->withGlobalLock(static fn (): string => 'inner') ?? 'outer')
        );

        $this->assertSame('outer', $outer, 'Вложенный захват блокировки должен быть отклонён');
    }

    private function makeDir(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            $this->fail('Не удалось создать каталог ' . $path);
        }
    }

    private function write(string $relative, string $contents): void
    {
        $this->writeTo($this->root, $relative, $contents);
    }

    private function writeTo(string $root, string $relative, string $contents): void
    {
        $path = $root . '/' . $relative;
        $this->makeDir(dirname($path));
        file_put_contents($path, $contents);
    }

    private function read(string $relative): string
    {
        $contents = @file_get_contents($this->root . '/' . $relative);
        $this->assertNotFalse($contents);

        return (string) $contents;
    }
}
