<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Вставка SEO-блоков в HTML: одно правило — один владелец.
 *
 * Правила были выписаны трижды — в `Content\Services\PageRenderer`,
 * `Seo\Services\LandingPageRenderer` и `Events\Http\EventPageShell` — и
 * докблок `PageRenderer::safeJsonLd()` даже обещал «при появлении ТРЕТЬЕГО
 * потребителя выделить `Nabilet\Core\Support\JsonLd::escape()`». Третий
 * потребитель существовал в момент, когда обещание было написано, поэтому
 * обещание не выполнилось никогда.
 *
 * Гейты этого не ловят (это не статус-словарь и не схема), поэтому копию
 * сторожат тесты: каждый литерал правила обязан жить только у владельца.
 * Комментарии вырезаются — иначе объяснение «почему не здесь» само считалось
 * бы копией.
 */
final class SeoEmbeddingCopyTest extends TestCase
{
    /** Литерал/выражение → единственный файл, где он имеет право быть. */
    private const OWNERS = [
        // Экранирование JSON-LD: `str_replace(['<', '>'], …)`.
        "~str_replace\\(\\s*\\[\\s*'<'~" => '/app/Core/Support/JsonLd.php',
        // Обёртка `<script type="application/ld+json">`.
        "~application/ld\\+json\">'~" => '/app/Core/Support/JsonLd.php',
        // Обёртка `dataLayer.push` с идемпотентностью.
        '~w\\.dataLayer\\s*=\\s*w\\.dataLayer~' => '/app/Core/Support/DataLayer.php',
        // Формат мета-тега: `sprintf('<meta … content="%s" />')`.
        "~sprintf\\(\\s*['\"]<meta~" => '/app/Core/Support/MetaTag.php',
        // Коды подтверждения прав на сайт.
        "~['\"]yandex-verification['\"]~" => '/app/Core/Http/SearchVerification.php',
        "~['\"]google-site-verification['\"]~" => '/app/Core/Http/SearchVerification.php',
    ];

    public function testEverySeoEmbeddingRuleHasExactlyOneOwner(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            $code = $this->code((string) file_get_contents($path));

            foreach (self::OWNERS as $pattern => $owner) {
                if (! str_ends_with($path, $owner) && preg_match($pattern, $code) === 1) {
                    $offenders[] = $this->relative($path) . ' → ' . $owner;
                }
            }
        }

        $this->assertSame([], $offenders, 'копия правила вставки SEO-блока');
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
