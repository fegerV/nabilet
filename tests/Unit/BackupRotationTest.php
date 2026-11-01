<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Backups\Services\BackupRotation;
use Nabilet\Tests\Support\TestCase;

/**
 * Политика ротации бэкапов.
 *
 * Ошибка здесь не «лишний файл на диске», а потеря последней резервной копии.
 * Поэтому проверяются не только «старое удаляется», но и все предохранители:
 *   - защищённое имя (только что созданный бэкап) не удаляется даже при keep=0;
 *   - полные архивы и дампы БД — независимые очереди;
 *   - посторонние файлы, пути с навигацией и несуществующие даты не считаются
 *     бэкапами и не попадают под удаление;
 *   - `keep` больше числа файлов — ничего не удаляется.
 *
 * Тест чистый: ни файловой системы, ни сети — работает в dependency-free наборе.
 */
final class BackupRotationTest extends TestCase
{
    /** @return list<string> */
    private function daily(int $count, string $prefix = 'db_nabilet', string $ext = 'sql'): array
    {
        $names = [];

        for ($i = 1; $i <= $count; $i++) {
            $names[] = sprintf('%s_2026-01-%02d_03-00-00.%s', $prefix, $i, $ext);
        }

        return $names;
    }

    public function test_oldest_copies_beyond_keep_are_selected(): void
    {
        $expired = BackupRotation::selectExpired($this->daily(10), 7);

        $this->assertCount(3, $expired);
        $this->assertSame([
            'db_nabilet_2026-01-01_03-00-00.sql',
            'db_nabilet_2026-01-02_03-00-00.sql',
            'db_nabilet_2026-01-03_03-00-00.sql',
        ], $expired);
    }

    public function test_nothing_is_deleted_when_keep_exceeds_the_number_of_copies(): void
    {
        $this->assertSame([], BackupRotation::selectExpired($this->daily(3), 7));
    }

    public function test_the_current_backup_is_never_deleted(): void
    {
        $current = 'db_nabilet_2026-01-10_03-00-00.sql';

        $expired = BackupRotation::selectExpired($this->daily(10), 0, [$current]);

        $this->assertNotContains($current, $expired);
        $this->assertCount(9, $expired);
    }

    public function test_protected_name_wins_even_when_it_is_not_the_newest(): void
    {
        $oldButProtected = 'db_nabilet_2026-01-01_03-00-00.sql';

        $expired = BackupRotation::selectExpired($this->daily(10), 0, [$oldButProtected]);

        $this->assertNotContains($oldButProtected, $expired);
        $this->assertCount(9, $expired);
    }

    public function test_full_archives_and_database_dumps_are_independent_queues(): void
    {
        $names = array_merge(
            $this->daily(3, 'db_nabilet', 'sql'),
            $this->daily(3, 'full', 'zip'),
        );

        $expired = BackupRotation::selectExpired($names, 2);

        // По одному старому файлу из каждой очереди, а не два из общей кучи.
        $this->assertCount(2, $expired);
        $this->assertContains('db_nabilet_2026-01-01_03-00-00.sql', $expired);
        $this->assertContains('full_2026-01-01_03-00-00.zip', $expired);
    }

    public function test_encrypted_backups_are_rotated_too(): void
    {
        $names = [
            'db_nabilet_2026-01-01_03-00-00.sql.enc',
            'db_nabilet_2026-01-02_03-00-00.sql.enc',
            'full_2026-01-01_03-00-00.zip.enc',
            'full_2026-01-02_03-00-00.zip.enc',
        ];

        $expired = BackupRotation::selectExpired($names, 1);

        $this->assertContains('db_nabilet_2026-01-01_03-00-00.sql.enc', $expired);
        $this->assertContains('full_2026-01-01_03-00-00.zip.enc', $expired);
        $this->assertCount(2, $expired);
    }

    public function test_foreign_files_are_not_backups(): void
    {
        $foreign = [
            'README.txt',
            'db_nabilet.sql',
            'db_nabilet_2026-01-01_03-00-00.sql.bak',
            'full_2026-01-01_03-00-00.zip.tmp',
            'db_nabilet_2026-13-99_99-99-99.sql',
            'db_nabilet_2026-02-30_03-00-00.sql',
            '../db_nabilet_2026-01-01_03-00-00.sql',
            'C:\\db_nabilet_2026-01-01_03-00-00.sql',
        ];

        foreach ($foreign as $name) {
            $this->assertFalse(
                BackupRotation::isBackupName($name),
                "постороннее имя принято за бэкап: {$name}"
            );
        }

        $this->assertSame([], BackupRotation::selectExpired($foreign, 0));
    }

    public function test_a_valid_name_is_recognised(): void
    {
        $this->assertTrue(BackupRotation::isBackupName('db_nabilet_2026-01-01_03-00-00.sql'));
        $this->assertTrue(BackupRotation::isBackupName('db_nabilet_2026-01-01_03-00-00.sql.enc'));
        $this->assertTrue(BackupRotation::isBackupName('full_2026-01-01_03-00-00.zip'));
        $this->assertTrue(BackupRotation::isBackupName('full_2026-01-01_03-00-00.zip.enc'));
    }

    public function test_negative_keep_is_rejected(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            static fn () => BackupRotation::selectExpired([], -1),
        );
    }
}
