<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * «Разрешил идентификатор — или 404»: один владелец вместо трёх копий.
 *
 * Извлечение `IdentifierLookup::first()` закрыло только ПОЛОВИНУ правила:
 * способ построить запрос стал общим, а способ на него отреагировать — нет.
 * Политика «не нашлось — 404» осталась выписанной вручную в трёх местах:
 *
 *   - `CheckinDeviceController::find()`
 *   - `OfflineBundleController::findDevice()`
 *   - `OrderController::refund()`
 *
 * Две первые были побайтово одинаковыми приватными методами. Разойдясь, они
 * дали бы разный код ошибки на двух путях админки, открывающих ОДНО И ТО ЖЕ
 * устройство, — или молча ушли в 500 на одном из них.
 *
 * Владелец — `Core/Database/IdentifierLookup::firstOrFail()`.
 *
 * Сторож ловит возврат копии по её форме: вызов `first()` (вариант «может
 * вернуть null») РЯДОМ с собственным `throw new NotFoundError` в том же файле.
 * У владельца оба есть ровно потому, что он эту политику и определяет.
 *
 * Проверка ищет КОД, а не упоминания: докблоки вырезаются, иначе объяснение
 * «почему не здесь» само считалось бы нарушением.
 */
final class IdentifierResolveOrFailCopyTest extends TestCase
{
    private const OWNER = '/Core/Database/IdentifierLookup.php';

    public function testTheResolveOrFailPolicyLivesOnlyInItsOwner(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere('~IdentifierLookup::first\(~', '~throw\s+new\s+NotFoundError~'),
            'политика «не нашлось — 404» выписана здесь: берите IdentifierLookup::firstOrFail()',
        );
    }

    /**
     * Файлы `app/`, в коде которых встречаются оба шаблона, кроме владельца.
     *
     * @return list<string>
     */
    private function filesWhere(string $first, string $last): array
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            if (str_ends_with($path, self::OWNER)) {
                continue;
            }

            $code = $this->code((string) file_get_contents($path));

            if (preg_match($first, $code) === 1 && preg_match($last, $code) === 1) {
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
