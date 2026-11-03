<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Nabilet\Modules\System\Services\HostChecks;
use Tests\TestCase;

/**
 * `system:selfcheck` — активный контур сигналов.
 *
 * Юнит-тесты (`SelfCheckEvaluatorTest`) проверяют ПОЛИТИКУ переходов на
 * чистой функции; этот тест проверяет, что команда ей СЛЕДУЕТ на живом
 * приложении: сигнал пишется в канал `alerts`, письмо уходит ровно тогда,
 * когда настроен адрес, повтор того же инцидента молчит, `--force`
 * повторяет сигнал об инциденте, восстановление фиксируется один раз.
 *
 * ПОДМЕНЫ
 *   `HostChecks` — final, поэтому не наследуем: подменяем через контейнер
 *   (`app->bind`), направляя проверку бэкапов во временный каталог, а диск
 *   — на заведомо здоровые числа (шов `disk*Bytes` в конструкторе). Без
 *   подмены диска тест зависел бы от заполненности диска той машины, где
 *   его прогоняют.
 *
 * ПОЧЕМУ НЕ Mail::fake()
 *   `MailFake::raw()` — пустая операция: raw-письма не регистрируются, и
 *   `assertSent` на них не ответит. Письма уходят через штатный
 *   array-транспорт (MAIL_MAILER=array в phpunit.xml): записав адрес в
 *   конфиг, читаем результат из `ArrayTransport::messages()`.
 *
 * ПОЧЕМУ НЕ RefreshDatabase
 *   Команда не трогает таблицы: проба БД — `select 1` (`HealthProbe`),
 *   состояние — в кэше, журнал — в `storage/logs`. Миграции здесь были бы
 *   дорогим ритуалом без содержания.
 */
final class SelfCheckCommandTest extends TestCase
{
    private const STATE_KEY = 'system:selfcheck:digest';

    /** Каталог, в который смотрит подменённая проверка бэкапов. */
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Остаток чужого прогона (ручного запуска или другого теста) изменил
        // бы переходы: этот тест требует известного «первого прогона».
        $this->forgetAlertLogs();
        Cache::forget(self::STATE_KEY);

        $this->backupDir = sys_get_temp_dir() . '/nabilet-selfcheck-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            @unlink((string) $file);
        }

        @rmdir($this->backupDir);

        $this->forgetAlertLogs();
        Cache::forget(self::STATE_KEY);

        parent::tearDown();
    }

    public function test_first_healthy_run_is_silent_and_writes_baseline(): void
    {
        $this->bindHost('fresh');
        config(['nabilet.alerts.email' => 'ops@example.test']);

        $code = Artisan::call('system:selfcheck');
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('status: ok', $output);
        $this->assertSame(0, $this->alertCount(), 'здоровый первый прогон не пишет в журнал алертов');
        $this->assertSame([], $this->sentSubjects(), 'здоровый первый прогон не пишет писем, даже если адрес задан');

        $state = Cache::get(self::STATE_KEY);
        $this->assertIsArray($state, 'после прогона должен быть записан отпечаток состояния');
        $this->assertNotEmpty($state['digest'] ?? '');
    }

    public function test_missing_backups_alert_critically_and_send_email(): void
    {
        $this->bindHost('none');
        config(['nabilet.alerts.email' => 'ops@example.test']);

        $code = Artisan::call('system:selfcheck');

        $this->assertNotSame(0, $code, 'инцидент обязан давать ненулевой код выхода');
        $this->assertSame(1, $this->alertCount(), 'первый прогон с проблемой — ровно одна запись');
        $this->assertStringContainsString('.CRITICAL:', $this->alertLog(), 'бэкапов нет — это критический уровень');
        $this->assertStringContainsString('backup', $this->alertLog());

        $subjects = $this->sentSubjects();
        $this->assertSame(['NABILET: самопроверка — проблемы (backup)'], $subjects);
    }

    public function test_unchanged_incident_stays_silent(): void
    {
        $this->bindHost('none');

        Artisan::call('system:selfcheck');
        $afterFirst = $this->alertCount();
        $this->assertSame(1, $afterFirst, 'санитарно: первый прогон записал сигнал');

        $code = Artisan::call('system:selfcheck');

        $this->assertNotSame(0, $code, 'инцидент продолжает отражаться в коде выхода');
        $this->assertSame($afterFirst, $this->alertCount(), 'тот же состав проблем не повторяется');
    }

    public function test_recovery_is_reported_once(): void
    {
        $this->bindHost('none');
        config(['nabilet.alerts.email' => 'ops@example.test']);

        Artisan::call('system:selfcheck');
        $this->assertSame(1, $this->alertCount(), 'санитарно: первый прогон записал сигнал');

        touch($this->backupDir . '/nabilet_fresh.sql', time());

        $code = Artisan::call('system:selfcheck');

        $this->assertSame(0, $code, 'после восстановления система здорова');
        $this->assertSame(2, $this->alertCount(), 'восстановление — событие, о нём пишут');
        $this->assertStringContainsString('состояние восстановлено', $this->alertLog());
        $subjects = $this->sentSubjects();
        $this->assertSame('NABILET: состояние восстановлено', (string) end($subjects));

        Artisan::call('system:selfcheck');
        $this->assertSame(2, $this->alertCount(), 'здоровое состояние продолжает молчать');
    }

    public function test_force_repeats_incident_signal(): void
    {
        $this->bindHost('none');

        Artisan::call('system:selfcheck');
        $afterFirst = $this->alertCount();
        $this->assertSame(1, $afterFirst, 'санитарно: первый прогон записал сигнал');

        Artisan::call('system:selfcheck', ['--force' => true]);

        $this->assertSame(
            $afterFirst + 1,
            $this->alertCount(),
            '--force обязан повторить сигнал об инциденте, даже если состояние не менялось',
        );
    }

    public function test_stale_backup_is_a_warning_not_a_critical_incident(): void
    {
        $this->bindHost('stale');
        config(['nabilet.alerts.email' => 'ops@example.test']);

        $code = Artisan::call('system:selfcheck');

        $this->assertNotSame(0, $code, 'протухший бэкап — инцидент');
        $this->assertStringContainsString('.WARNING:', $this->alertLog());
        $this->assertStringNotContainsString('.CRITICAL:', $this->alertLog(), 'деградация не имеет уровень аварии');
        $this->assertContains('NABILET: самопроверка — проблемы (backup)', $this->sentSubjects());
    }

    /**
     * Подменить `HostChecks`: каталог бэкапов — во временной директории
     * (`fresh|stale|none`), диск — заведомо здоровые 50 %. Реальный бэкап-
     * каталог машины на тест не влияет.
     */
    private function bindHost(string $mode): void
    {
        if (! is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0777, true);
        }

        if ($mode === 'fresh') {
            touch($this->backupDir . '/nabilet_test.sql', time());
        }

        if ($mode === 'stale') {
            touch(
                $this->backupDir . '/nabilet_test.sql',
                time() - (HostChecks::BACKUP_STALE_HOURS + 4) * 3600,
            );
        }

        // 'none' — каталог существует, копий нет: это ошибка проверки.

        $dir = $this->backupDir;

        $this->app->bind(HostChecks::class, fn () => new HostChecks(
            $dir,
            500.0 * 1024 ** 3,
            1000.0 * 1024 ** 3,
        ));
    }

    private function forgetAlertLogs(): void
    {
        foreach (glob(storage_path('logs/alerts-*.log')) ?: [] as $file) {
            @unlink((string) $file);
        }
    }

    /** Полный текст журнала алертов за все дни. */
    private function alertLog(): string
    {
        $text = '';

        foreach (glob(storage_path('logs/alerts-*.log')) ?: [] as $file) {
            $text .= (string) file_get_contents((string) $file);
        }

        return $text;
    }

    /** Сколько записей самопроверки сейчас в журнале алертов. */
    private function alertCount(): int
    {
        return substr_count($this->alertLog(), 'Самопроверка:');
    }

    /**
     * Темы писем, поступивших на array-транспорт (`MailFake::raw` — пустышка).
     *
     * @return list<string>
     */
    private function sentSubjects(): array
    {
        $transport = $this->app['mail.manager']->mailer()->getSymfonyTransport();

        $subjects = [];

        foreach ($transport->messages() as $sent) {
            $subjects[] = (string) ($sent->getOriginalMessage()->getSubject() ?? '');
        }

        return $subjects;
    }
}
