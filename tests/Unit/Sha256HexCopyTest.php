<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Отпечаток `CHAR(64)` — один владелец, а не десять копий `hash('sha256', …)`.
 *
 * Правило стояло в десяти местах, и семь из них заполняют колонки `CHAR(64)`
 * (`session_token_hash`, `qr_token_hash`, `device_token_hash`, `api_keys.key_hash`
 * ×2, `idempotency_records.request_hash`, `offline_bundles.bundle_hash`).
 * Три из них — запись и ДВА чтения одного и того же `device_token_hash`, и они
 * обязаны были совпадать молча.
 *
 * ПОЧЕМУ ЭТО НЕ БЕЗОБИДНО, ХОТЯ И НЕ ПАДАЕТ. Алгоритм не «сильный хеш вообще»,
 * а ровно тот, чья hex-запись совпадает с шириной колонки. Разойдясь, копии не
 * роняют тест и не роняют сборку: токен просто перестаёт находиться по
 * `WHERE device_token_hash = :hash`, и живой сканер получает 401 на входе.
 *
 * Сторож намеренно узок: `hash_file('sha256', …)` в `MediaService` — ДРУГОЕ
 * правило (отпечаток файла, не секрета), поэтому шаблон требует именно `hash(`
 * с литералом алгоритма, а не любое упоминание sha256.
 *
 * Проверка ищет КОД, а не упоминания: докблоки вырезаются, иначе объяснение
 * «почему не здесь» само считалось бы нарушением.
 */
final class Sha256HexCopyTest extends TestCase
{
    private const OWNER = '/Core/Support/Sha256Hex.php';

    public function testTheStoredDigestIsComputedOnlyByItsOwner(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere('~hash\(\s*[\'"]sha256[\'"]~'),
            'вторая копия отпечатка: считайте через Core\Support\Sha256Hex',
        );
    }

    /**
     * Файлы `app/`, в коде которых встречается шаблон, кроме владельца.
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
