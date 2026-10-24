<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * `Content-Type` не объявляется литералом на месте — только через владельца.
 *
 * Пара `response($html)->header('Content-Type', 'text/html; charset=utf-8')`
 * стояла в пяти местах; ещё пять литералов объявляли JSON, XML, CSV и PDF.
 * Гейты этого не ловят (это не статус-словарь и не схема), поэтому копию
 * сторожит тест.
 *
 * ЧТО ИМЕННО ИЩЕТСЯ. Не любые упоминания MIME-строк, а формы, которыми тип
 * ОБЪЯВЛЯЕТСЯ: `'Content-Type' => '…'` в списке заголовков, `->header(
 * 'Content-Type', '…')` и `->withBody($body, '…')`. Поэтому под проверку не
 * попадают:
 *   - `Accept`-заголовки (`ForceJsonResponse`, импорт площадок) — это то, что
 *     мы просим у чужого сервера, а не объявление о своём теле;
 *   - список разрешённых к загрузке типов в `MediaController` — другое
 *     правило и другой владелец, совпадение строки там случайно;
 *   - JS в blade-шаблоне установщика — там `headers: {...}` с двоеточием, а не
 *     `=>`.
 *
 * Комментарии вырезаются: иначе объяснение «почему не здесь» само считалось бы
 * копией.
 */
final class ContentTypeVocabularyTest extends TestCase
{
    /** Формы, которыми тип объявляется ЛИТЕРАЛОМ. */
    private const FORBIDDEN = [
        // 'Content-Type' => 'text/html; charset=utf-8'
        "~['\"]Content-Type['\"]\\s*=>\\s*['\"]~",
        // ->header('Content-Type', 'text/html; charset=utf-8')
        // Значение обязано быть кавычкой: ->header('Content-Type',
        // ContentType::HTML) — это как раз правильная форма, её не трогаем.
        "~->header\\(\\s*['\"]Content-Type['\"]\\s*,\\s*['\"]~",
        // ->withBody($body, 'application/json')
        "~->withBody\\([^)]*['\"]application/json['\"]~",
    ];

    public function testContentTypeIsNeverDeclaredInline(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            $code = $this->code((string) file_get_contents($path));

            foreach (self::FORBIDDEN as $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $offenders[] = $this->relative($path) . ' → ' . $pattern;
                }
            }
        }

        $this->assertSame([], $offenders, 'Content-Type объявлен литералом вместо Core\Http\ContentType');
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
