<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Секрет подписи билетов не имеет права совпадать с ключом приложения.
 *
 * ДЕФЕКТ (аудит S-01). `TicketService` и его прежняя копия в
 * `TicketScanService` читали ключ так:
 *
 *     $secret = config('app.key') ?? config('tickets.qr_secret');
 *
 * При незаполненном `TICKET_QR_SECRET` билеты молча подписывались `APP_KEY`.
 * Последствия: утечка главного ключа позволяла подделать любой билет, а
 * плановая ротация (`php artisan key:generate`) обнуляла все выпущенные
 * билеты. Фабрика `QrSignerFactory` закрыла рабочий путь, но старые методы
 * `generateQrCode()` / `verifyQrCodeSignature()` оставались в классе мёртвым
 * кодом — то есть готовым к повторному подключению.
 *
 * Этот тест — статический сторож, а не проверка поведения: он читает код
 * модуля `Tickets` и падает, если фолбэк на `APP_KEY` или второе место чтения
 * `qr_secret` появится снова. Проверка через `token_get_all()` отбрасывает
 * комментарии и строковые литералы, поэтому докблоки, которые ОБЪЯСНЯЮТ
 * запрет, не считаются нарушением.
 */
final class TicketQrSigningKeyTest extends TestCase
{
    private const TICKETS_DIR = '/app/Modules/Tickets';

    /**
     * Ключ приложения и историческое имя `tickets.qr_secret` не должны
     * встречаться в коде подписи QR.
     */
    public function testTicketModuleNeverSignsWithTheApplicationKey(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(self::TICKETS_DIR) as $file) {
            $code = $this->codeWithoutComments($file);

            if (preg_match(
                '/\b(?:config|env)\s*\(\s*[\'"](?:app\.key|APP_KEY)[\'"]/i',
                $code,
                $match
            ) === 1) {
                $offenders[] = $this->relative($file) . ' → ' . $match[0];
            }

            if (preg_match('/\bconfig\s*\(\s*[\'"]tickets\.qr_secret[\'"]/i', $code) === 1) {
                $offenders[] = $this->relative($file) . " → config('tickets.qr_secret')";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'QR-билеты обязаны подписываться только TICKET_QR_SECRET через QrSignerFactory; '
            . 'фолбэк на APP_KEY снова появился в: ' . implode(', ', $offenders)
        );
    }

    /**
     * `nabilet.ticket.qr_secret` читается ровно в одном месте.
     *
     * Две копии чтения уже расходились в этом проекте: `TicketService` и
     * `TicketScanService` держали одинаковый код с комментарием «скопировано».
     * Один владелец — условие, при котором проверка выше вообще что-то значит.
     */
    public function testQrSecretIsReadInExactlyOnePlace(): void
    {
        $readers = [];

        foreach ($this->phpFiles(self::TICKETS_DIR) as $file) {
            $code = $this->codeWithoutComments($file);

            if (str_contains($code, 'nabilet.ticket.qr_secret')) {
                $readers[] = $this->relative($file);
            }
        }

        $readers = array_values(array_unique($readers));

        $this->assertSame(
            ['app/Modules/Tickets/Services/QrSignerFactory.php'],
            $readers,
            'секрет QR должен читаться только в QrSignerFactory, иначе копии разойдутся'
        );
    }

    /** @return list<string> */
    private function phpFiles(string $relativeDir): array
    {
        $base = dirname(__DIR__, 2) . $relativeDir;
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen(dirname(__DIR__, 2)) + 1));
    }

    /**
     * Код без комментариев. Строковые литералы остаются: `config('app.key')`
     * без аргумента-строки не существует.
     */
    private function codeWithoutComments(string $file): string
    {
        $raw = file_get_contents($file);

        if ($raw === false) {
            $this->fail('Не удалось прочитать ' . $file);
        }

        $code = '';

        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
