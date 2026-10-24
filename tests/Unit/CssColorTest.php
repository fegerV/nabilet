<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\CssColor;
use Nabilet\Tests\Support\TestCase;

/**
 * Правило «это CSS-цвет?» — один владелец.
 *
 * Проверка hex-цвета была выписана четырьмя копиями в трёх файлах, и три из
 * четырёх были регистрозависимыми. Живой ошибки это не давало только потому,
 * что каждый вызывающий приводил регистр сам; цена копии — в следующей правке,
 * которую надо не забыть внести во все четыре места.
 *
 * Второй тест сканирует `app/`: регулярка hex-цвета обязана жить ТОЛЬКО в
 * `CssColor`. Гейта на это нет (это не статус-словарь), поэтому копию сторожит
 * тест — как у IdempotencyHeader и NotificationStatus.
 */
final class CssColorTest extends TestCase
{
    public function testShortHexIsAcceptedAndKeptShort(): void
    {
        // Короткая запись НЕ разворачивается: normalizeHex отвечает на вопрос
        // «валиден ли», а не «как записать полным». Разворачивание — expand().
        $this->assertSame('#abc', CssColor::normalizeHex('#abc'));
        $this->assertSame('#aabbcc', CssColor::normalizeHex('#aabbcc'));
    }

    public function testUppercaseIsTheSameColour(): void
    {
        // Копия в TicketPdfRenderer принимала оба регистра, три остальные — нет.
        // Регистр не делает цвет другим, поэтому принимается любой.
        $this->assertSame('#ff5c22', CssColor::normalizeHex('#FF5C22'));
        $this->assertSame('#ff5c22', CssColor::normalizeHex('#Ff5C22'));
        $this->assertSame('#abc', CssColor::normalizeHex('  #ABC  '));
    }

    public function testMalformedValuesAreRefused(): void
    {
        $refused = [
            '',            // пусто
            '#',           // только решётка
            '#ab',         // две цифры
            '#abcd',       // четыре — это уже альфа, её мы не поддерживаем
            '#aabbccdd',   // восемь — тоже альфа
            'aabbcc',      // без решётки
            '#ggg',        // не hex-цифры
            'red',         // имя, а не hex
            '#ff5c2',      // пять цифр
            '#ff5c222',    // семь цифр
        ];

        foreach ($refused as $value) {
            $this->assertNull(CssColor::normalizeHex($value), 'должно быть отвергнуто: ' . $value);
        }
    }

    public function testNonStringIsRefused(): void
    {
        $this->assertNull(CssColor::normalizeHex(null));
        $this->assertNull(CssColor::normalizeHex(42));
        $this->assertNull(CssColor::normalizeHex(['#abc']));
        $this->assertNull(CssColor::normalizeHex(true));
    }

    public function testShortHexExpandsForChannelMaths(): void
    {
        // На `#abc` substr($hex, 0, 2) дал бы `ab` вместо `aa` — поэтому
        // разворачивание обязано произойти до разбора по каналам.
        $this->assertSame('#aabbcc', CssColor::expand('#abc'));
        $this->assertSame('#aabbcc', CssColor::expand('#ABC'));
        $this->assertSame('#aabbcc', CssColor::expand('#aabbcc'));
    }

    public function testNamedColoursAreThePaletteVocabulary(): void
    {
        foreach (['black', 'white', 'transparent', 'inherit'] as $name) {
            $this->assertTrue(CssColor::isNamed($name), $name);
            $this->assertTrue(CssColor::isNamed(strtoupper($name)), $name . ' в верхнем регистре');
        }

        // `none` — ключевое слово заливки, а не цвет: палитре билета он не нужен,
        // потому что контраст для него не считается.
        $this->assertFalse(CssColor::isNamed('none'));
        $this->assertFalse(CssColor::isNamed('red'));
    }

    public function testFillKeywordsIncludeNoneForSvg(): void
    {
        $this->assertTrue(CssColor::isFillKeyword('none'));
        $this->assertTrue(CssColor::isFillKeyword('NONE'));
        $this->assertTrue(CssColor::isFillKeyword('black'));
        $this->assertFalse(CssColor::isFillKeyword('red'));
    }

    public function testNormalizeFillCoversTheWidestCase(): void
    {
        // Это проверка рендерера PDF: hex, имя цвета и `none` проходят.
        $this->assertSame('#ff5c22', CssColor::normalizeFill('#FF5C22'));
        $this->assertSame('#abc', CssColor::normalizeFill('#ABC'));
        $this->assertSame('none', CssColor::normalizeFill('NONE'));
        $this->assertSame('transparent', CssColor::normalizeFill('Transparent'));
        $this->assertNull(CssColor::normalizeFill('red'));
        $this->assertNull(CssColor::normalizeFill(''));
        $this->assertNull(CssColor::normalizeFill(null));
    }

    public function testNoOtherFileDeclaresTheHexPattern(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app'));
        foreach ($rii as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_ends_with(str_replace('\\', '/', $path), '/CssColor.php')) {
                continue;
            }

            // Только код: в докблоках эти регулярки цитируются как история.
            $code = (string) preg_replace(
                ['~/\*.*?\*/~s', '~//[^\n]*~'],
                '',
                (string) file_get_contents($path)
            );

            if (preg_match('~0-9a-f\]\{3\}~i', $code) === 1) {
                $offenders[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
            }
        }

        $this->assertSame([], $offenders, 'вторая копия регулярки hex-цвета');
    }
}
