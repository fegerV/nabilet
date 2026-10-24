<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * HMAC и байты, которые подписываются, — один владелец, а не копии.
 *
 * Правило «подпись = HMAC-SHA256(тело, секрет) → сравнить постоянным временем»
 * стояло в пяти местах, а `'sha256'` — в восьми. Две из копий были
 * подписывающей и проверяющей сторонами ОДНОГО протокола (`X-Nabilet-Signature`),
 * и совпадение их канонизации держалось на предложении в докблоке джобы.
 *
 * Три вещи, которые обязаны жить в одном месте:
 *
 *  1. `hash_hmac(...)` — алгоритм и hex-формат. Вторая копия означает, что
 *     смена алгоритма произойдёт в одном месте из двух, и подписчик перестанет
 *     принимать наши подписи (или мы перестанем принимать подписи провайдера).
 *  2. `json_encode` с флагами подписи — байты. Разошедшиеся флаги дают разные
 *     байты для одного payload, и подпись честной стороны не сходится.
 *     `TicketService` уже писал эти флаги в ДРУГОМ ПОРЯДКЕ, чем остальные три
 *     места, — доказательство, что их копировали руками.
 *  3. `HmacSignature::digest()` — сырой дайджест. Единственный законный
 *     потребитель вне владельца — `QrSigner` (base64url в QR); всем остальным
 *     нужен `sign()` или `verify()`, где сравнение уже постоянное по времени.
 *
 * Проверка ищет КОД, а не упоминания: докблоки вырезаются, иначе объяснение
 * «почему не здесь» само считалось бы нарушением.
 */
final class HmacSignatureCopyTest extends TestCase
{
    private const HMAC_OWNER = '/Core/Support/HmacSignature.php';

    private const PAYLOAD_OWNER = '/Core/Support/SignedPayload.php';

    /**
     * Законный потребитель `digest()` вне владельца: QR кодирует подпись в
     * base64url, а не в hex. Это формат QR, а не второй алгоритм.
     */
    private const RAW_DIGEST_CONSUMER = '/Core/Support/QrSigner.php';

    public function testTheHmacPrimitiveLivesOnlyInItsOwner(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere('~hash_hmac\s*\(~', [self::HMAC_OWNER]),
            'вторая копия вычисления HMAC: подпись должна считаться через HmacSignature',
        );
    }

    public function testSignedBytesAreSerialisedOnlyByTheirOwner(): void
    {
        // Именно ПАРА флагов и именно в этом порядке: `JSON_PRETTY_PRINT | …`
        // (лог для человека), `… | JSON_THROW_ON_ERROR` (отпечаток офлайн-бандла)
        // и `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES` (аудит) — другие
        // правила, у них свои владельцы и своя политика отказа.
        $this->assertSame(
            [],
            $this->filesWhere(
                '~json_encode\s*\([^,]+,\s*JSON_UNESCAPED_UNICODE\s*\|\s*JSON_UNESCAPED_SLASHES\s*[,)]~',
                [self::PAYLOAD_OWNER],
            ),
            'вторая копия флагов подписываемой сериализации',
        );
    }

    public function testTheRawDigestIsNotTakenDirectlyOutsideTheQrFormat(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere('~HmacSignature::digest\s*\(~', [self::HMAC_OWNER, self::RAW_DIGEST_CONSUMER]),
            'сырой дайджест вместо sign()/verify(): сравнение в обход постоянного времени',
        );
    }

    /**
     * Файлы `app/`, в коде которых встречается шаблон, кроме перечисленных.
     *
     * @param  list<string>  $allowed  суффиксы путей от корня `app/`
     * @return list<string>
     */
    private function filesWhere(string $pattern, array $allowed): array
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            if ($this->isAllowed($path, $allowed)) {
                continue;
            }

            if (preg_match($pattern, $this->code((string) file_get_contents($path))) === 1) {
                $offenders[] = $this->relative($path);
            }
        }

        return $offenders;
    }

    /** @param list<string> $allowed */
    private function isAllowed(string $path, array $allowed): bool
    {
        foreach ($allowed as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return true;
            }
        }

        return false;
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
