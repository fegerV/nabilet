<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Подстановка `{{переменной}}` и поиск шаблона — один владелец, а не копии.
 *
 * Правило стояло дважды: в `TransactionalMailService` (письма о заказе) и в
 * `AccountMailService` (сброс пароля, подтверждение адреса). Копии разошлись:
 * в письмах об аккаунте осталась первая версия подстановки — без ветки
 * `Htmlable` и без защиты от нескалярных значений. Живого дефекта не было
 * только потому, что список переменных там состоит из трёх скаляров.
 *
 * Гейт этого не ловит (это не статус-словарь), поэтому копию сторожит тест:
 * `str_replace` с `{{` в первом аргументе и запрос по `locale` к
 * `NotificationTemplate` обязаны жить только в
 * `Services/NotificationTemplateRenderer.php`.
 *
 * Проверка ищет именно КОД, а не упоминания: докблоки вырезаются, иначе
 * объяснение «почему не здесь» само считалось бы нарушением.
 */
final class NotificationTemplateRenderCopyTest extends TestCase
{
    private const OWNER = '/Modules/Notifications/Services/NotificationTemplateRenderer.php';

    public function testPlaceholderSubstitutionLivesOnlyInTheRenderer(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere("~str_replace\\(\\s*['\"]\\{\\{~"),
            'вторая копия подстановки {{переменной}}',
        );
    }

    public function testTemplateLookupLivesOnlyInTheRenderer(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            if (str_ends_with($path, self::OWNER)) {
                continue;
            }

            $code = $this->code((string) file_get_contents($path));

            if (str_contains($code, 'NotificationTemplate::query()')
                && preg_match("~->where\\(\\s*'locale'~", $code) === 1) {
                $offenders[] = $this->relative($path);
            }
        }

        $this->assertSame([], $offenders, 'вторая копия поиска действующего шаблона');
    }

    /**
     * Файлы `app/`, в коде которых встречается шаблон регулярного выражения.
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
