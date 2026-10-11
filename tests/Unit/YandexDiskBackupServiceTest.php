<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Backups\Services\YandexDiskBackupService;
use Nabilet\Tests\Support\TestCase;

/**
 * Пароль БД не попадает в командную строку (аудит FINAL-ACCEPTANCE, B-2 / P0-2).
 *
 * ДЕФЕКТ. `mysqldump -p<secret>` видел любой пользователь хоста через
 * `ps auxf` в момент выполнения дампа, и та же строка оседала в истории shell и
 * в логах оркестраторов. Пароль передавался аргументом, потому что так короче —
 * и именно поэтому его приходилось передавать в место, которое читают все.
 *
 * КАК ПРОВЕРЯЕТСЯ. Свойство «секрета нет в командной строке» нельзя доказать,
 * запустив `ps` в тесте, зато можно доказать структурно, и это сильнее:
 *
 *   - построитель команды НЕ ПРИНИМАЕТ пароль вообще — нельзя утечь то, чего
 *     функция не получает (проверяется отражением по списку параметров);
 *   - в собранной строке нет ни пароля, ни флага `-p`;
 *   - пароль лежит в файле `[client]`, который `mysqldump` читает через
 *     `--defaults-extra-file`, и этот файл создаётся с правами 0600.
 *
 * Тест намеренно не трогает файловую систему и не вызывает `exec()`: он должен
 * работать в dependency-free наборе (`php tests/run.php`), то есть без Composer
 * и без MySQL. Поэтому проверяются два чистых метода, а не весь дамп.
 */
final class YandexDiskBackupServiceTest extends TestCase
{
    private const SECRET = 's3cr3t-pa$$word';

    /**
     * Построитель команды не принимает пароль — утечь нечему.
     *
     * Это главная проверка файла. Проверять «в строке нет пароля» можно только
     * для конкретного значения: подставь другой секрет — и утверждение
     * перестанет что-либо значить. Отсутствие ПАРАМЕТРА закрывает класс целиком.
     */
    public function testTheCommandBuilderCannotReceiveAPassword(): void
    {
        $method = new \ReflectionMethod(YandexDiskBackupService::class, 'buildDumpCommand');

        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters()
        );

        $this->assertSame(
            ['credentialsFile', 'database', 'backupPath'],
            $names,
            'построитель команды получил лишний параметр — проверьте, не пароль ли это'
        );
    }

    /** В собранной команде нет ни пароля, ни флага `-p`. */
    public function testTheDumpCommandCarriesNoPassword(): void
    {
        $command = YandexDiskBackupService::buildDumpCommand(
            '/tmp/nabilet_dump_abc123',
            'nabilet',
            '/var/backups/db_nabilet.sql'
        );

        $this->assertFalse(
            str_contains($command, self::SECRET),
            'пароль оказался в командной строке'
        );

        // Любой токен `-p`: и `-p<secret>`, и голый `-p`. Второй тоже дефект —
        // `mysqldump` в этом случае ЖДЁТ ввода пароля, то есть задача по
        // расписанию не завершится, а повиснет до таймаута крона. Проверка на
        // «`-p` со значением» этот случай пропускала.
        $this->assertSame(
            0,
            preg_match('/(?:^|\s)-p/', $command),
            'в команде есть флаг -p — пароль снова уходит аргументом'
        );
    }

    /**
     * Креденшелы передаются файлом, и файл назван в команде.
     *
     * Без `--defaults-extra-file` дамп просто не получил бы пароль и упал бы с
     * «Access denied», так что проверка не формальность: она удерживает связь
     * между двумя методами.
     */
    public function testTheDumpCommandPointsAtTheCredentialsFile(): void
    {
        $command = YandexDiskBackupService::buildDumpCommand(
            '/tmp/nabilet_dump_abc123',
            'nabilet',
            '/var/backups/db_nabilet.sql'
        );

        $this->assertStringContainsString('--defaults-extra-file=', $command);
        $this->assertStringContainsString(escapeshellarg('/tmp/nabilet_dump_abc123'), $command);
    }

    /**
     * Дамп снимается согласованно и с триггерами.
     *
     * `--single-transaction` обязателен: без него `mysqldump` блокирует таблицы,
     * то есть бэкап по расписанию останавливал бы продажи. `--triggers
     * --routines` — потому что схема намеренно опирается на триггеры
     * неизменяемости версий схемы залов: без них восстановление дало бы другую
     * базу, а не ту же самую.
     */
    public function testTheDumpCommandKeepsTheConsistencyFlags(): void
    {
        $command = YandexDiskBackupService::buildDumpCommand('/tmp/creds', 'nabilet', '/tmp/out.sql');

        $this->assertStringContainsString('--single-transaction', $command);
        $this->assertStringContainsString('--triggers', $command);
        $this->assertStringContainsString('--routines', $command);
    }

    /** Имя базы экранируется: пробел или `;` в нём не должны стать командой. */
    public function testTheDatabaseNameIsShellEscaped(): void
    {
        $database = 'nabilet; rm -rf /';

        $command = YandexDiskBackupService::buildDumpCommand('/tmp/creds', $database, '/tmp/out.sql');

        $this->assertStringContainsString(escapeshellarg($database), $command);
        // Сырое имя в команде означало бы, что `;` разделил строку на две команды.
        $this->assertFalse(
            str_contains($command, $database . ' '),
            'имя базы попало в команду неэкранированным'
        );
    }

    /**
     * Пароль ЕСТЬ в файле креденшелов — и это правильно.
     *
     * Опасен не файл, а аргумент командной строки: файл читает только
     * `mysqldump`, он создаётся с правами 0600 и удаляется в `finally`. Если бы
     * пароля не было и здесь, дамп молча пошёл бы без аутентификации.
     */
    public function testTheCredentialsFileHoldsThePassword(): void
    {
        $contents = YandexDiskBackupService::credentialsFileContents(
            '127.0.0.1',
            3307,
            'nabilet',
            self::SECRET
        );

        $this->assertStringContainsString('[client]', $contents);
        $this->assertStringContainsString('host="127.0.0.1"', $contents);
        $this->assertStringContainsString('port=3307', $contents);
        $this->assertStringContainsString('user="nabilet"', $contents);
        $this->assertStringContainsString('password="' . self::SECRET . '"', $contents);
    }

    /**
     * Кавычка и обратный слэш в пароле не ломают INI-файл.
     *
     * Пароль со `"` закрыл бы значение раньше времени, а `\` в конце — экранировал
     * бы закрывающую кавычку. В обоих случаях `mysqldump` прочитал бы обрезанный
     * пароль, получил «Access denied» и уронил бэкап — то есть отказ проявился бы
     * не в момент смены пароля, а в момент, когда бэкап понадобился.
     */
    public function testTheCredentialsFileEscapesQuotesAndBackslashes(): void
    {
        $contents = YandexDiskBackupService::credentialsFileContents('h', 3306, 'u', 'pa"ss\\word');

        $this->assertStringContainsString('password="pa\\"ss\\\\word"', $contents);
    }
}
