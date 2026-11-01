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
    private const PHASE_THREE_MIGRATION = '/database/migrations/2026_11_02_000100_enforce_organization_id_on_tenant_tables.php';
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

    /**
     * Фаза 3 обязана закрыть ровно те же таблицы, что открыла фаза 1.
     *
     * Списки неизбежно дублируются (одна миграция добавляет колонку, другая
     * делает её обязательной), но разойтись они не должны: таблица, забытая в
     * фазе 3, останется с nullable `organization_id` навсегда и снова откроет
     * межтенантную утечку. Сторож читает константы, а не комментарии.
     */
    public function testPhaseThreeMigrationCoversExactlyThePhaseOneTables(): void
    {
        $phaseOne = array_values(array_unique($this->extract(
            $this->absolute(self::MIGRATION),
            '/\'([a-z_]+)\' => \'/',
        )));

        $phaseThree = array_values(array_unique($this->extractList(
            $this->absolute(self::PHASE_THREE_MIGRATION),
            'TABLES',
        )));

        sort($phaseOne);
        sort($phaseThree);

        $this->assertTrue($phaseThree !== [], 'Миграция фазы 3 не содержит ни одной таблицы.');
        $this->assertSame(
            $phaseOne,
            $phaseThree,
            'Миграции фаз 1 и 3 разошлись по составу таблиц класса A.'
        );
    }

    /**
     * Элементы списковой константы миграции (`private const NAME = [ ... ];`).
     *
     * @return list<string>
     */
    private function extractList(string $file, string $constant): array
    {
        $code = $this->codeWithoutComments($file);

        if (! preg_match('/const\s+' . preg_quote($constant, '/') . '\s*=\s*\[(.*?)\];/s', $code, $body)) {
            $this->fail(sprintf('В %s нет константы %s.', $file, $constant));
        }

        preg_match_all('/\'([a-z_]+)\'/', $body[1], $matches);

        return $matches[1];
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
