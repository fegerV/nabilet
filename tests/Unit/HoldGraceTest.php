<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Инвариант: grace-окно холда имеет ЕДИНСТВЕННЫЙ источник.
 *
 * Зачем этот тест существует. Число «сколько ещё секунд после `expires_at`
 * платёж считается валидным» было продублировано литералом `addMinutes(5)` /
 * `subMinutes(5)` в пяти местах трёх модулей. Копии не связаны друг с другом,
 * и расхождение между ними — не косметика: sweeper вернул бы место в продажу,
 * пока вебхук оплаты ещё считает холд живым, и покупатель, нажавший «оплатить»
 * за секунду до истечения, потерял бы место, уже оплатив его.
 *
 * Раньше тест требовал более слабое: «каждый потребитель читает один и тот же
 * КЛЮЧ конфига». Это допускало три независимые реализации одной арифметики —
 * ровно ту конфигурацию, из которой дефект и вырос. Теперь требование сильнее:
 * ключ читает РОВНО ОДИН файл (`HoldGrace`), а все потребители обязаны звать
 * его методы и не повторять ни число, ни сравнение дат.
 *
 * Тест читает исходники как текст (юнит-раннер этого проекта не грузит
 * фреймворк, поэтому `config()` здесь недоступен). Это дешёвый замок против
 * возврата класса дефекта «мёртвая/дублированная ручка» —
 * см. docs/CODE-QUALITY-GUIDE.md §3 и §9 (P2).
 */
final class HoldGraceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * Единственный файл, которому разрешено читать ключ конфига grace-окна.
     */
    private const SINGLE_SOURCE = 'app/Core/Support/HoldGrace.php';

    /**
     * Маркер чтения конфига в коде. Именно с префиксом `config(`: без него имя
     * ключа встречается в докблоках по всему проекту как проза, и тест ловил бы
     * комментарии вместо кода.
     */
    private const CONFIG_READ = "config('nabilet.checkout.hold_grace_minutes'";

    /**
     * Файлы, которые обязаны брать окно у `HoldGrace`, а не считать его сами.
     * Список намеренно явный: если появится шестой потребитель, тест должен
     * заставить добавить его сюда и подключить к тому же источнику, а не завести
     * очередную копию правила.
     */
    private const CONSUMERS = [
        'app/Modules/Inventory/Services/HoldSweeper.php',
        'app/Modules/Inventory/Services/SeatHoldLifecycle.php',
        'app/Modules/Orders/Services/StaleOrderExpirer.php',
        'app/Modules/Orders/Models/SeatHold.php',
        'app/Modules/Payments/Services/PaymentService.php',
    ];

    private const CONFIG_KEY = 'nabilet.checkout.hold_grace_minutes';

    public function testConfigDeclaresTheSingleSource(): void
    {
        $config = $this->read('config/nabilet.php');

        $this->assertStringContainsString(
            "'hold_grace_minutes' => (int) env('CHECKOUT_HOLD_GRACE_MINUTES', 5)",
            $config,
            'Ключ конфига grace-окна исчез или переименован.'
        );
    }

    /**
     * Ключ читается ровно в одном файле дерева `app/`.
     *
     * Это главный замок: второй читатель — это второй набор правил, даже если
     * сегодня оба возвращают 5.
     */
    public function testConfigKeyIsReadInExactlyOneFile(): void
    {
        $readers = [];

        foreach ($this->appPhpFiles() as $relative => $source) {
            if (str_contains($source, self::CONFIG_READ)) {
                $readers[] = $relative;
            }
        }

        $this->assertCount(
            1,
            $readers,
            'Читателей grace-ключа должно быть ровно один; найдено: ' . implode(', ', $readers)
        );

        $this->assertSame(
            self::SINGLE_SOURCE,
            $readers[0] ?? '',
            'Grace-окно читает не тот файл, который объявлен единственным источником.'
        );
    }

    public function testConsumersDoNotReadTheConfigKeyDirectly(): void
    {
        foreach (self::CONSUMERS as $file) {
            $this->assertFalse(
                str_contains($this->read($file), self::CONFIG_READ),
                "{$file}: читает ключ конфига напрямую — окно должно приходить из HoldGrace."
            );
        }
    }

    public function testConsumersUseTheSharedHelper(): void
    {
        foreach (self::CONSUMERS as $file) {
            $this->assertStringContainsString(
                'HoldGrace',
                $this->read($file),
                "{$file}: не использует HoldGrace — правило окна снова может разойтись с остальными."
            );
        }
    }

    public function testSingleSourceActuallyReadsTheConfigKey(): void
    {
        $this->assertStringContainsString(
            self::CONFIG_READ,
            $this->read(self::SINGLE_SOURCE),
            'HoldGrace перестал читать ключ конфига — окно стало неоткуда брать.'
        );
    }

    public function testNoHardcodedGraceLiteralRemains(): void
    {
        foreach (self::CONSUMERS as $file) {
            $source = $this->read($file);

            $this->assertFalse(
                str_contains($source, 'addMinutes(5)'),
                "{$file}: вернулся литерал addMinutes(5) вместо конфига."
            );

            $this->assertFalse(
                str_contains($source, 'subMinutes(5)'),
                "{$file}: вернулся литерал subMinutes(5) вместо конфига."
            );
        }
    }

    public function testExampleEnvDocumentsTheLiveKnobNotTheDeadOne(): void
    {
        $env = $this->read('.env.example');

        $this->assertStringContainsString(
            'CHECKOUT_HOLD_GRACE_MINUTES=5',
            $env,
            '.env.example больше не документирует живую ручку grace-окна.'
        );

        $this->assertFalse(
            str_contains($env, 'NABILET_HOLD_GRACE='),
            '.env.example снова объявляет мёртвую ручку NABILET_HOLD_GRACE — её никто не читает.'
        );
    }

    private function read(string $relative): string
    {
        $path = self::ROOT . '/' . $relative;

        $this->assertTrue(is_file($path), "Файл не найден: {$relative}");

        return (string) file_get_contents($path);
    }

    /**
     * Все `*.php` под `app/`, в виде «путь относительно корня проекта» → текст.
     *
     * @return array<string, string>
     */
    private function appPhpFiles(): array
    {
        $root = str_replace('\\', '/', realpath(self::ROOT . '/app') ?: (self::ROOT . '/app'));

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $relative = 'app/' . ltrim(substr($path, strlen($root)), '/');

            $files[$relative] = (string) file_get_contents($path);
        }

        return $files;
    }
}
