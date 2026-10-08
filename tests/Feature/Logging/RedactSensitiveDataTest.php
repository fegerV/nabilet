<?php

declare(strict_types=1);

namespace Tests\Feature\Logging;

use Monolog\Level;
use Monolog\LogRecord;
use Illuminate\Support\Facades\Log;
use Nabilet\Core\Logging\RedactSensitiveData;
use Tests\TestCase;

/**
 * Маскирование персональных данных на уровне `Monolog\LogRecord` (152-ФЗ).
 *
 * Почему это Feature-тест, а не Unit: `Monolog\LogRecord` объявлен в vendor, а
 * зависимостно-свободный раннер `tests/run.php` намеренно не подключает
 * Composer — там проверяется только строковый вход (`scrubText`). Разбор
 * `$context` и исключений требует настоящего `LogRecord`, поэтому живёт здесь.
 *
 * ЧТО ЗДЕСЬ ГЛАВНОЕ
 *   Не маскирование по ключу (оно очевидно), а два неочевидных канала:
 *   1. `\Throwable` в `$context` — Laravel включает трассировку, а
 *      `getTraceAsString()` печатает аргументы вызовов. Без разбора
 *      исключения вручную в лог попадали аргументы целиком, включая пароли.
 *   2. Проводка фильтра в каналы. Класс, который никто не подключил, — это не
 *      защита, а её имитация: ровно так в этом проекте уже выглядели
 *      `APP_ONLY_TABLES` и `KNOWN_SPEC_INDEX_DIVERGENCES` — списки, которые
 *      никто не читал.
 */
final class RedactSensitiveDataTest extends TestCase
{
    private RedactSensitiveData $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new RedactSensitiveData();
    }

    /** @param array<string, mixed> $context */
    private function record(string $message, array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable('2026-10-08 12:00:00'),
            channel: 'testing',
            level: Level::Error,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    public function test_sensitive_context_keys_are_replaced_entirely(): void
    {
        $scrubbed = ($this->redactor)($this->record('checkout', [
            'email' => 'ivan@example.com',
            'password' => 'hunter2',
            'api_token' => 'sk-live-123',
            'order_id' => 1042,
        ]));

        $this->assertSame(RedactSensitiveData::MASK, $scrubbed->context['email']);
        $this->assertSame(RedactSensitiveData::MASK, $scrubbed->context['password']);
        $this->assertSame(RedactSensitiveData::MASK, $scrubbed->context['api_token']);

        // Нечувствительные ключи не трогаем: иначе фильтр начнёт съедать
        // диагностику и его отключат.
        $this->assertSame(1042, $scrubbed->context['order_id']);
    }

    public function test_safe_keys_survive_despite_sounding_sensitive(): void
    {
        $scrubbed = ($this->redactor)($this->record('ok', [
            'email_verified_at' => '2026-10-01 10:00:00',
            'card_last4' => '4242',
        ]));

        $this->assertSame('2026-10-01 10:00:00', $scrubbed->context['email_verified_at']);
        $this->assertSame('4242', $scrubbed->context['card_last4']);
    }

    public function test_nested_context_is_scrubbed(): void
    {
        $scrubbed = ($this->redactor)($this->record('ok', [
            'user' => ['contact' => ['email' => 'a@b.ru'], 'id' => 7],
        ]));

        $this->assertSame(RedactSensitiveData::MASK, $scrubbed->context['user']['contact']['email']);
        $this->assertSame(7, $scrubbed->context['user']['id']);
    }

    public function test_context_values_are_scrubbed_by_shape_too(): void
    {
        $scrubbed = ($this->redactor)($this->record('ok', [
            'note' => 'позвонить +7 999 123-45-67',
        ]));

        $this->assertStringContainsString(RedactSensitiveData::MASK, $scrubbed->context['note']);
    }

    public function test_extra_is_scrubbed(): void
    {
        $scrubbed = ($this->redactor)($this->record('ok', [], ['user_email' => 'x@y.ru']));

        $this->assertSame(RedactSensitiveData::MASK, $scrubbed->extra['user_email']);
    }

    public function test_message_is_scrubbed(): void
    {
        $scrubbed = ($this->redactor)($this->record('Письмо ушло на ivan@example.com'));

        $this->assertStringContainsString('i***@example.com', $scrubbed->message);
        $this->assertFalse(str_contains($scrubbed->message, 'ivan@example.com'));
    }

    // ── исключения: канал, который утекал молча ───────────────────────────────

    public function test_throwable_in_context_loses_call_arguments(): void
    {
        $exception = $this->exceptionWithSecretArgument();

        $scrubbed = ($this->redactor)($this->record('не удалось войти', ['exception' => $exception]));

        $rendered = json_encode($scrubbed->context['exception'], JSON_UNESCAPED_UNICODE);

        // Главное: аргумента вызова в записи нет. Именно он утекал, потому что
        // `getTraceAsString()` печатает аргументы, а фильтровалось только
        // сообщение.
        $this->assertFalse(str_contains($rendered, 'hunter2'), 'Аргумент вызова попал в лог');
        $this->assertFalse(str_contains($rendered, 'ivan@example.com'));

        // При этом запись остаётся полезной для диагностики.
        $this->assertSame(\RuntimeException::class, $scrubbed->context['exception']['class']);
        $this->assertArrayHasKey('file', $scrubbed->context['exception']);
    }

    public function test_throwable_message_is_scrubbed(): void
    {
        $exception = new \RuntimeException('SQLSTATE[23000]: duplicate for ivan@example.com');

        $scrubbed = ($this->redactor)($this->record('ошибка', ['exception' => $exception]));

        $this->assertFalse(str_contains($scrubbed->context['exception']['message'], 'ivan@example.com'));
    }

    public function test_previous_exception_is_normalised_too(): void
    {
        $previous = new \RuntimeException('причина');
        $exception = new \RuntimeException('следствие', 0, $previous);

        $scrubbed = ($this->redactor)($this->record('ошибка', ['exception' => $exception]));

        $this->assertArrayHasKey('previous', $scrubbed->context['exception']);
        $this->assertSame(\RuntimeException::class, $scrubbed->context['exception']['previous']['class']);
    }

    public function test_stringified_exception_in_message_loses_trace_arguments(): void
    {
        // Путь `Log::error($e)`: Laravel приводит исключение к строке, и
        // `__toString()` вклеивает трассу вместе с аргументами.
        $scrubbed = ($this->redactor)($this->record((string) $this->exceptionWithSecretArgument()));

        $this->assertFalse(str_contains($scrubbed->message, 'hunter2'));
        $this->assertFalse(str_contains($scrubbed->message, 'ivan@example.com'));
    }

    public function test_record_is_not_mutated_in_place(): void
    {
        // `LogRecord` объявлен с readonly-свойствами, поэтому процессор обязан
        // возвращать новый экземпляр. Прямое присваивание — фатал.
        $original = $this->record('ok', ['email' => 'ivan@example.com']);

        $scrubbed = ($this->redactor)($original);

        $this->assertNotSame($original, $scrubbed);
        $this->assertSame('ivan@example.com', $original->context['email']);
        $this->assertSame(RedactSensitiveData::MASK, $scrubbed->context['email']);
    }

    // ── проводка ─────────────────────────────────────────────────────────────

    public function test_the_resolved_channels_actually_carry_the_processor(): void
    {
        // ЭТОТ ТЕСТ ЗАМЕНИЛ ПРОВЕРКУ СОДЕРЖИМОГО КОНФИГА, И ЭТО ГЛАВНОЕ
        // ИСПРАВЛЕНИЕ ЗДЕСЬ. Прежняя версия утверждала, что
        // `config('logging.channels.daily.processors')` содержит класс, — и
        // проходила, пока маскирование НЕ РАБОТАЛО ВООБЩЕ. Причин было две, и
        // обе ловятся только на разрешённом канале:
        //
        //   1. Драйвер `daily` ключ `processors` не читает: он собирается в
        //      `LogManager::createRotatingDriver()` с жёстким списком
        //      процессоров. Конфиг выглядел правильно, эффекта не было.
        //   2. `LogManager::createMonologDriver()` требует от процессора
        //      `Monolog\Processor\ProcessorInterface` и иначе бросает
        //      `InvalidArgumentException` — канал не создаётся, Laravel
        //      уходит в emergency logger.
        //
        // Проверка на разрешённом объекте ловит оба случая: первый — тем, что
        // процессора в списке не окажется, второй — исключением при разрешении.
        foreach (['daily', 'security'] as $channel) {
            $logger = Log::channel($channel);

            // `channel()` отдаёт `Illuminate\Log\Logger`, который форвардит
            // вызовы к вложенному Monolog-логгеру через `__call`, поэтому
            // `getProcessors()` здесь возвращает то, что реально навешено.
            $processors = $logger->getProcessors();
            $this->assertIsArray($processors, "{$channel} не отдал список процессоров");

            $applied = array_filter(
                $processors,
                static fn ($processor): bool => $processor instanceof RedactSensitiveData
            );

            $this->assertCount(
                1,
                $applied,
                "Канал {$channel} не применяет RedactSensitiveData (проверено на разрешённом логгере)"
            );
        }
    }

    public function test_the_daily_channel_writes_redacted_lines_end_to_end(): void
    {
        // Самая сильная проверка: запись через РЕАЛЬНЫЙ канал из конфига и
        // чтение того, что оказалось на диске. Именно так дефект и был найден —
        // в `storage/logs/laravel-*.log` лежали адрес и пароль покупателя,
        // включая аргументы в трассировке исключения.
        $base = sys_get_temp_dir() . '/nabilet-redaction-probe-' . uniqid();
        $pattern = $base . '-*';

        config(['logging.channels.daily.handler_with.filename' => $base]);
        Log::forgetChannel('daily');

        try {
            Log::channel('daily')->error('Письмо ушло на ivan.petrov@example.com', [
                'password' => 'hunter2',
            ]);

            $files = glob($pattern) ?: [];
            $this->assertCount(1, $files, 'Канал daily не создал файл журнала');

            $written = (string) file_get_contents($files[0]);

            $this->assertFalse(str_contains($written, 'ivan.petrov@example.com'), 'Адрес попал в файл журнала');
            $this->assertFalse(str_contains($written, 'hunter2'), 'Пароль попал в файл журнала');
            $this->assertStringContainsString('i***@example.com', $written);
            $this->assertStringContainsString(RedactSensitiveData::MASK, $written);
        } finally {
            foreach (glob($pattern) ?: [] as $file) {
                @unlink($file);
            }
            Log::forgetChannel('daily');
        }
    }

    public function test_the_processor_itself_redacts_when_attached_directly(): void
    {
        // Проверка самого процессора, в отрыве от конфигурации Laravel: она
        // отвечает на вопрос «правильно ли он маскирует», а не «подключён ли
        // он». Второй вопрос закрывают два теста выше.
        $handler = new \Monolog\Handler\TestHandler();
        $logger = new \Monolog\Logger('probe', [$handler], [
            new RedactSensitiveData(),
        ]);

        $logger->error('Письмо ушло на ivan@example.com', ['password' => 'hunter2']);

        $record = $handler->getRecords()[0];

        $this->assertFalse(str_contains($record->message, 'ivan@example.com'));
        $this->assertSame(RedactSensitiveData::MASK, $record->context['password']);
    }

    public function test_the_processor_satisfies_monologs_processor_contract(): void
    {
        // `LogManager::createMonologDriver()` проверяет это через `is_a()` и
        // бросает исключение. Проверяем прямо, чтобы причина была названа, а не
        // выяснялась из падения создания канала.
        $this->assertInstanceOf(
            \Monolog\Processor\ProcessorInterface::class,
            new RedactSensitiveData()
        );
    }

    /**
     * Исключение, выброшенное из функции с секретным аргументом, — ровно та
     * форма, в которой персональные данные попадали в трассу.
     */
    private function exceptionWithSecretArgument(): \Throwable
    {
        $login = static function (string $email, string $password): void {
            throw new \RuntimeException('Не удалось войти');
        };

        try {
            $login('ivan@example.com', 'hunter2');
        } catch (\Throwable $e) {
            return $e;
        }

        return new \RuntimeException('unreachable');
    }
}
