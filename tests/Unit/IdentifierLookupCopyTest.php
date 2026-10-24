<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * «id или public_id → запрос» — один владелец, а не копии в контроллерах.
 *
 * Форма `isNumeric($value) ? … whereKey … : … where('public_id' …` стояла в
 * трёх местах, и два из них — побайтово одинаковые приватные методы `find()` и
 * `findDevice()` в двух контроллерах Checkin. Теперь правило живёт в
 * `Core\Database\IdentifierLookup`, а этот сторож не даёт его вернуть.
 *
 * Сторож ловит ФОРМУ, в которой копия действительно существовала: тернарник по
 * ответу `DatabaseId::isNumeric`. `SessionLocator` и `TicketLocator` тоже знают
 * про два идентификатора, но решают другую задачу (имя → внутренний id, с
 * `ValidationError`) и тернарником не пользуются — поэтому под шаблон не попадают.
 *
 * Проверка ищет КОД, а не упоминания: докблоки вырезаются, иначе объяснение
 * «почему не здесь» само считалось бы нарушением.
 */
final class IdentifierLookupCopyTest extends TestCase
{
    private const OWNER = '/Core/Database/IdentifierLookup.php';

    /** Владелец вопроса «это id?» — тернарник внутри `numericOrNull()` законен. */
    private const RULE_OWNER = '/Core/Support/DatabaseId.php';

    public function testTheEitherOrLookupLivesOnlyInItsOwner(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere('~isNumeric\([^)]*\)\s*\?~'),
            'вторая копия правила «число — по ключу, остальное — по public_id»',
        );
    }

    /**
     * Файлы `app/`, в коде которых встречается шаблон, кроме владельцев.
     *
     * @return list<string>
     */
    private function filesWhere(string $pattern): array
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            if (str_ends_with($path, self::OWNER) || str_ends_with($path, self::RULE_OWNER)) {
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
