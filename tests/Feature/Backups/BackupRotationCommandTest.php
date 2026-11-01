<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Tests\TestCase;

/**
 * `backup:rotate` — ручная очистка старых копий.
 *
 * Проверяется поведение на реальной файловой системе:
 *   - `--keep=2` оставляет две последние копии, старые удаляет;
 *   - `--dry-run` показывает план, но НИЧЕГО не удаляет;
 *   - посторонний файл в каталоге бэкапов не трогается (fail-closed).
 *
 * Имена датированы 2099 годом, чтобы посторонние файлы, оставленные другими
 * тестами, не влияли на то, какие копии считаются новыми: ротация сортирует по
 * имени, а не по mtime.
 */
final class BackupRotationCommandTest extends TestCase
{
    private const PREFIX = 'db_rotationtest_';

    /** @return list<string> */
    private function seedBackups(int $count): array
    {
        $dir = storage_path('app/backups');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $names = [];

        for ($i = 1; $i <= $count; $i++) {
            $name = sprintf('%s2099-01-%02d_00-00-00.sql', self::PREFIX, $i);
            file_put_contents($dir . '/' . $name, 'dump');
            $names[] = $name;
        }

        return $names;
    }

    /** @param list<string> $names */
    private function remove(array $names): void
    {
        $dir = storage_path('app/backups');

        foreach ($names as $name) {
            @unlink($dir . '/' . $name);
        }
    }

    private function path(string $name): string
    {
        return storage_path('app/backups/' . $name);
    }

    public function test_rotation_keeps_the_newest_copies_and_leaves_foreign_files(): void
    {
        $names = $this->seedBackups(5);
        $foreign = 'rotationtest-not-a-backup.txt';

        file_put_contents($this->path($foreign), 'keep me');

        try {
            $this->artisan('backup:rotate', ['--local-only' => true, '--keep' => 2])
                ->assertExitCode(0);

            $this->assertFalse(is_file($this->path($names[0])), 'самая старая копия должна быть удалена');
            $this->assertFalse(is_file($this->path($names[1])), 'вторая старая копия должна быть удалена');
            $this->assertFalse(is_file($this->path($names[2])), 'третья старая копия должна быть удалена');

            $this->assertTrue(is_file($this->path($names[3])), 'предпоследняя копия должна остаться');
            $this->assertTrue(is_file($this->path($names[4])), 'последняя копия должна остаться');

            $this->assertTrue(is_file($this->path($foreign)), 'посторонний файл не является бэкапом и не удаляется');
        } finally {
            $this->remove(array_merge($names, [$foreign]));
        }
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $names = $this->seedBackups(3);

        try {
            $this->artisan('backup:rotate', ['--local-only' => true, '--keep' => 1, '--dry-run' => true])
                ->assertExitCode(0);

            foreach ($names as $name) {
                $this->assertTrue(is_file($this->path($name)), "dry-run удалил файл: {$name}");
            }
        } finally {
            $this->remove($names);
        }
    }
}
