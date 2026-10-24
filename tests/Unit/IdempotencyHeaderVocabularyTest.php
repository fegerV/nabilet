<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Idempotency\IdempotencyHeader;
use Nabilet\Tests\Support\TestCase;

/**
 * Имя заголовка идемпотентности — один владелец, а не копии на потребителя.
 *
 * `Idempotency-Key` читается в четырёх местах (мидлвар, контроллер виджета,
 * фолбэк платежей, список CORS-заголовков). Пока имя было выписано в каждом
 * отдельно, переименование в контракте оставило бы одну из копий на старом
 * имени — и мидлвар читал бы не тот заголовок, что шлёт клиент.
 *
 * Второй тест сканирует исходники: литерал `'Idempotency-Key'` обязан жить
 * ТОЛЬКО в `IdempotencyHeader`. Гейт этого не ловит (это не статус-словарь),
 * поэтому копию сторожит тест.
 */
final class IdempotencyHeaderVocabularyTest extends TestCase
{
    public function testNameIsTheContractHeader(): void
    {
        $this->assertSame('Idempotency-Key', IdempotencyHeader::NAME);
    }

    public function testNoOtherClassDeclaresTheHeaderLiteral(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app'));
        foreach ($rii as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_ends_with(str_replace('\\', '/', $path), '/IdempotencyHeader.php')) {
                continue;
            }

            // Только код: убираем блочные и строчные комментарии, чтобы упоминания
            // имени в докблоках не считались копией.
            $code = (string) preg_replace(
                ['~/\*.*?\*/~s', '~//[^\n]*~'],
                '',
                (string) file_get_contents($path)
            );

            if (preg_match("~['\"]Idempotency-Key['\"]~", $code) === 1) {
                $offenders[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
            }
        }

        $this->assertSame([], $offenders, 'второй литерал Idempotency-Key');
    }
}
