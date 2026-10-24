<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * «Контекст → номер организации» — один владелец, а не копии в контроллерах.
 *
 * Преобразование `?string → ?int` стояло четырьмя одинаковыми копиями в
 * контроллерах чтения (`EventGalleryController`, `MediaController`,
 * `TicketTemplateController` и первая ветка `PromoCodeController::organizationIdFor()`),
 * и все четыре делали одно и то же: `tryId()` + `ctype_digit` + `(int)`.
 *
 * Теперь это `OrganizationContext::idOrNull()`, а сторож ловит сочетание двух
 * половин: файл, который спрашивает контекст через `tryId()` И сам приводит его
 * к числу. Одна половина без другой — не копия (можно спросить строку и не
 * приводить), поэтому проверка именно на ПАРУ.
 *
 * `PromoCodeController::organizationId()` под сторож не попадает намеренно:
 * там fail-closed правило (`id()` + отказ), а не мягкое чтение, и приводить
 * строку к числу оно не должно — оно бросает.
 *
 * Проверка ищет КОД, а не упоминания: докблоки вырезаются, иначе объяснение
 * «почему не здесь» само считалось бы нарушением.
 */
final class TenantIdConversionCopyTest extends TestCase
{
    private const OWNER = '/Core/Tenancy/OrganizationContext.php';

    public function testTheNullableTenantIdIsConvertedOnlyByItsOwner(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            if (str_ends_with($path, self::OWNER)) {
                continue;
            }

            $code = $this->code((string) file_get_contents($path));

            if (str_contains($code, '->tryId()') && str_contains($code, 'ctype_digit')) {
                $offenders[] = $this->relative($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'вторая копия «контекст → номер организации»: берите OrganizationContext::idOrNull()',
        );
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
