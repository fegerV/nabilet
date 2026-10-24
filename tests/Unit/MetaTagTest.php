<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\MetaTag;
use Nabilet\Tests\Support\TestCase;

/**
 * `<meta>`-тег: правило «пустое значение → нет тега» одно на весь проект.
 *
 * Проверка пустоты не косметическая: пустой
 * `<meta name="yandex-verification" content="">` — это утверждение «код
 * подтверждения есть, но он пустой», и Яндекс.Вебмастер читает его как ошибку.
 * Пока правило было выписано тринадцать раз в четырёх файлах, один из
 * рендереров рано или поздно вывел бы пустой тег — и заметить это можно было
 * бы только в панели вебмастера.
 */
final class MetaTagTest extends TestCase
{
    public function testNamedProducesAStandardMetaTag(): void
    {
        $this->assertSame(
            '<meta name="robots" content="noindex, follow" />',
            MetaTag::named('robots', 'noindex, follow'),
        );
    }

    public function testPropertyUsesThePropertyAttribute(): void
    {
        // Open Graph читает `property`, а не `name`: перепутанный атрибут
        // означает тег, которого парсер соцсети просто не увидит.
        $this->assertSame(
            '<meta property="og:image" content="https://example.test/p.jpg" />',
            MetaTag::property('og:image', 'https://example.test/p.jpg'),
        );
    }

    public function testEmptyValueProducesNoTagAtAll(): void
    {
        foreach ([null, '', '   ', [], true, false] as $empty) {
            $this->assertSame('', MetaTag::named('yandex-verification', $empty));
        }
    }

    public function testValueIsTrimmedAndEscaped(): void
    {
        $this->assertSame(
            '<meta name="geo.placename" content="Сургут &amp; &quot;район&quot;" />',
            MetaTag::named('geo.placename', '  Сургут & "район"  '),
        );
    }

    public function testValueNormalisesToNullForAnythingUnusable(): void
    {
        $this->assertSame('abc', MetaTag::value(' abc '));
        $this->assertNull(MetaTag::value(null));
        $this->assertNull(MetaTag::value('   '));
        $this->assertNull(MetaTag::value([]));
        $this->assertNull(MetaTag::value(true), 'bool — не значение атрибута');
    }
}
