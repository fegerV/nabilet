<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * «Это сотрудник?» — один владелец, а не три копии в FormRequest'ах.
 *
 * Одна и та же проверка роли стояла в `StoreEventRequest`, `UpdateEventRequest`
 * и `UpdateRowPricesRequest` побайтово одинаково:
 *
 *     $user->roles()->pluck('slug')->intersect(['admin', 'manager'])->isNotEmpty()
 *
 * То есть список ролей админ-API существовал в ТРЁХ местах (плюс
 * `EnsureAdminRole` и `StaffRole::ROLES`). Добавление роли `organizer`
 * потребовало бы правки пяти файлов, и типичная ошибка — поправить четыре.
 *
 * Владелец — `Core/Support/StaffRole`: он знает и список (`ROLES`), и то, что
 * «сотрудник» — класс доступа, а не владелец ресурса.
 *
 * Сторож держит оба конца правила: форму запроса (`roles()->pluck`) и сам
 * список (`['admin', 'manager']`). Появись копия в любом виде — расхождение
 * снова станет возможным.
 *
 * Проверка ищет КОД, а не упоминания: докблоки вырезаются, иначе объяснение
 * «почему не здесь» само считалось бы нарушением.
 */
final class StaffRoleCopyTest extends TestCase
{
    private const OWNER = '/Core/Support/StaffRole.php';

    public function testTheStaffCheckIsNotCopiedIntoAQueryForm(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere('~roles\(\)\s*->\s*pluck~'),
            'вторая копия проверки роли: берите StaffRole::isStaff()',
        );
    }

    public function testTheRoleListIsDeclaredOnlyByItsOwner(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere('~[\'"]admin[\'"]\s*,\s*[\'"]manager[\'"]~'),
            'второй список ролей админ-API: берите StaffRole::ROLES',
        );
    }

    /**
     * Файлы `app/`, в коде которых встречается шаблон, кроме владельца.
     *
     * @return list<string>
     */
    private function filesWhere(string $pattern): array
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            if (str_ends_with($path, self::OWNER)) {
                continue;
            }

            if (preg_match($pattern, $this->code((string) file_get_contents($path))) === 1) {
                $offenders[] = $this->relative($path);
            }
        }

        return $offenders;
    }

    /** @return list<string> */
    private function appFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/app';
        $paths = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($rii as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($paths);

        return $paths;
    }

    /** Только код: блочные и строчные комментарии вырезаны. */
    private function code(string $source): string
    {
        return (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $source);
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
