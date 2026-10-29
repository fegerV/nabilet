<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\Text;
use Nabilet\Tests\Support\TestCase;

/**
 * Класс управляющих символов C0 + DEL — СОБСТВЕННОСТЬ Nabilet\Core\Support\Text,
 * а не ручных копий в сервисах.
 *
 * ЧТО БЫЛО. Тот же класс `/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/` был вписан
 * в две точки — StorefrontService::text (с флагом `u`) и MatrixMessage::sanitize
 * (без флага `u`, намеренно: байтовый класс безопасен для невалидной UTF-8 из БД).
 * Копии компилировались, но расхождение по флагу `u` уже было — следующая правка
 * класса в одном месте (например, добавить `\\x85`) разошлась бы со вторым.
 *
 * Сторож не проверяет «класс существует» — он проверяет, что ВЕСЬ каталог app/
 * больше не содержит самого литерала класса нигде, кроме владельца Text.php, и что
 * оба прежних места зовут Text::stripControlChars(). Вернуть копию, не сломав
 * тест, нельзя: паттерн отлова вхождения не допускает.
 */
final class ControlCharsSingleOwnerTest extends TestCase
{
    /** Точный текст литерала класса символов из исходников. */
    private const CONTROL_CHARS_CLASS = '[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]';

    private const OWNER = 'app/Core/Support/Text.php';

    /** Исходник без комментариев: литерал правила в пояснении не должен ловиться. */
    private function codeOnly(string $source): string
    {
        return (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $source);
    }

    /** Все PHP-файлы под каталогом, плюс относительный путь (forward slashes). */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = [
                    'path' => $file->getPathname(),
                    'rel' => str_replace('\\', '/', substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1)),
                ];
            }
        }

        return $files;
    }

    public function test_control_char_class_lives_only_in_owner(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach ($this->phpFiles($root . '/app') as $entry) {
            if ($entry['rel'] === self::OWNER) {
                continue;
            }

            $code = $this->codeOnly((string) file_get_contents($entry['path']));
            if (str_contains($code, self::CONTROL_CHARS_CLASS)) {
                $offenders[] = $entry['rel'];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Класс управляющих символов скопирован вне Nabilet\Core\Support\Text: ' . implode(', ', $offenders)
        );
    }

    public function test_owner_is_called_by_both_former_copies(): void
    {
        $root = dirname(__DIR__, 2);
        $storefront = (string) file_get_contents($root . '/app/Modules/Storefront/Services/StorefrontService.php');
        $matrix = (string) file_get_contents($root . '/app/Modules/Matrix/Domain/MatrixMessage.php');

        $this->assertStringContainsString('Text::stripControlChars', $storefront);
        $this->assertStringContainsString('Text::stripControlChars', $matrix);
        $this->assertStringContainsString('stripControlChars', (string) file_get_contents($root . '/' . self::OWNER));
    }

    public function test_strips_control_chars_with_unicode_flag(): void
    {
        $this->assertSame('abcd', Text::stripControlChars("a\x00b\x07c\x1Fd", true));
        $this->assertSame('Привет мир!', Text::stripControlChars('Привет мир!', true));
    }

    public function test_strips_control_chars_without_unicode_flag(): void
    {
        $this->assertSame('abcd', Text::stripControlChars("a\x00b\x07c\x1Fd", false));
    }

    /**
     * Байтовый класс (unicode=false) не падает на невалидной UTF-8 — именно ради
     * этого MatrixMessage держал регулярку без флага `u`.
     */
    public function test_byte_class_survives_invalid_utf8(): void
    {
        $input = "x\x00y" . "\xFF";
        $this->assertSame('xy' . "\xFF", Text::stripControlChars($input, false));
    }
}
