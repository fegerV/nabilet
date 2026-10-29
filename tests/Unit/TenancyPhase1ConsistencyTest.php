<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Фаза 1: миграция колонок и команда бэкфилла не должны разойтись.
 *
 * Миграция `2026_10_29_000100_...` добавляет nullable `organization_id` для
 * таблиц класса A, а `TenancyBackfillOrganizationCommand` заполняет их. Если
 * таблица появится в одном месте и исчезнет из другого, колонка останется без
 * обратного заполнения — а переход к `NOT NULL` (фаза 3) станет невозможным.
 *
 * Это статический сторож: он читает оба файла, вытаскивает имена таблиц из
 * константы `TABLES` миграции и из массива шагов команды и требует полного
 * совпадения. Проверка через `token_get_all()` отбрасывает комментарии.
 */
final class TenancyPhase1ConsistencyTest extends TestCase
{
    private const MIGRATION = '/database/migrations/2026_10_29_000100_add_organization_id_to_tenant_tables.php';
    private const COMMAND = '/app/Modules/System/Console/TenancyBackfillOrganizationCommand.php';

    public function testMigrationAndBackfillCommandCoverTheSameTables(): void
    {
        $migrationTables = array_values(array_unique($this->extract(
            $this->absolute(self::MIGRATION),
            '/\'([a-z_]+)\' => \'/',
        )));

        $commandTables = array_values(array_unique($this->extract(
            $this->absolute(self::COMMAND),
            '/\'([a-z_]+)\' => \[/',
        )));

        sort($migrationTables);
        sort($commandTables);

        if ($migrationTables === []) {
            $this->fail('Миграция не содержит ни одной таблицы класса A.');
        }

        $this->assertSame(
            $migrationTables,
            $commandTables,
            'Миграция и команда бэкфилла разошлись по составу таблиц класса A.'
        );
    }

    /** @return list<string> */
    private function extract(string $file, string $pattern): array
    {
        $code = $this->codeWithoutComments($file);
        preg_match_all($pattern, $code, $matches);

        return $matches[1] ?? [];
    }

    private function absolute(string $relative): string
    {
        return dirname(__DIR__, 2) . $relative;
    }

    private function codeWithoutComments(string $file): string
    {
        $raw = file_get_contents($file);

        if ($raw === false) {
            $this->fail('Не удалось прочитать ' . $file);
        }

        $code = '';

        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
