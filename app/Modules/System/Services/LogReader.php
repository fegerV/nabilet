<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Services;

use Nabilet\Core\Logging\RedactSensitiveData;
use SplFileObject;

/**
 * Чтение логов приложения для админской страницы «Логи».
 *
 * ПОЧЕМУ НЕ `file()` ЦЕЛИКОМ
 *   Канал `daily` пишет в `laravel-YYYY-MM-DD.log`, и за сутки такой файл легко
 *   перерастает десятки мегабайт. Читать его целиком в HTTP-запросе — это
 *   memory_limit и таймаут на ровном месте. Поэтому сканируется только ХВОСТ
 *   файла (по умолчанию 8 МБ): администратор смотрит свежие записи, а не
 *   историю за сутки. Граница отсечения сдвигается до первой целой строки,
 *   иначе обрезанная запись выглядела бы как битая.
 *
 * ПОЧЕМУ ПАРСИМ, А НЕ ОТДАЁМ СТРОКИ
 *   Запись Laravel многострочная: за строкой заголовка идёт стектрейс, у
 *   которого нет ни даты, ни уровня. Отдавать такое списком строк — значит
 *   показывать администратору рваный текст, где фильтр по уровню работает
 *   только на первой строке из двадцати. Здесь строки склеиваются в записи,
 *   и уже запись — единица фильтрации, поиска и раскрытия в интерфейсе.
 *
 * ЧТО НЕ ОТДАЁТСЯ НАРУЖУ
 *   Ничего не отдаётся без `RedactSensitiveData::scrubText()`. Файл на диске
 *   уже прошёл фильтр канала, но в нём лежат записи, сделанные ДО его
 *   появления, — и показать их как есть означало бы воспроизвести утечку
 *   через интерфейс.
 */
class LogReader
{
    /** Сколько байт хвоста файла максимум сканировать. */
    private const TAIL_BYTES = 8 * 1024 * 1024;

    /** Уровни в порядке возрастания критичности (RFC 5424). */
    public const LEVELS = [
        'DEBUG',
        'INFO',
        'NOTICE',
        'WARNING',
        'ERROR',
        'CRITICAL',
        'ALERT',
        'EMERGENCY',
    ];

    /** Начало новой записи: `[2026-10-22 13:15:57] local.ERROR: текст`. */
    private const HEADER = '/^\[(?<date>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}[^\]]*)\]\s+(?<env>[^.\s]+)\.(?<level>[A-Z]+):\s?(?<message>.*)$/';

    public function __construct(private readonly RedactSensitiveData $redactor)
    {
    }

    /**
     * Доступные файлы логов, свежие первыми.
     *
     * Список строится по тому же glob, что и выбор файла при чтении, и это
     * единственный источник допустимых имён: параметр `file` из запроса
     * сверяется с ним, поэтому `../../.env` до `fopen` не доходит.
     *
     * @return list<array{name: string, size: int, modified_at: string, is_current: bool}>
     */
    public function files(): array
    {
        $paths = $this->paths();
        $current = $this->newestPath();

        $files = [];

        foreach ($paths as $path) {
            $files[] = [
                'name' => basename($path),
                'size' => (int) (@filesize($path) ?: 0),
                'modified_at' => date('c', (int) (@filemtime($path) ?: 0)),
                'is_current' => $path === $current,
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcmp($b['modified_at'], $a['modified_at']));

        return $files;
    }

    /**
     * Прочитать записи выбранного файла.
     *
     * @param  string|null  $file  имя файла из `files()`; `null` — самый свежий
     * @param  list<string>  $levels  фильтр по уровням (пусто — все)
     * @param  string|null  $search  подстрока без учёта регистра
     * @param  int  $limit  сколько записей вернуть
     * @param  int  $offset  сколько подходящих записей пропустить
     * @return array{
     *     file: string|null,
     *     entries: list<array<string, mixed>>,
     *     matched: int,
     *     total_entries: int,
     *     limit: int,
     *     offset: int,
     *     truncated: bool,
     *     levels_present: array<string, int>
     * }|null `null` — файла нет или имя недопустимо
     */
    public function read(?string $file, array $levels = [], ?string $search = null, int $limit = 200, int $offset = 0): ?array
    {
        $path = $this->resolve($file);

        if ($path === null) {
            return null;
        }

        $entries = $this->parse($this->tail($path));

        $levelsPresent = [];
        foreach ($entries as $entry) {
            $levelsPresent[$entry['level']] = ($levelsPresent[$entry['level']] ?? 0) + 1;
        }
        ksort($levelsPresent);

        $wanted = array_values(array_unique(array_map(static fn ($l): string => strtoupper((string) $l), $levels)));
        $needle = $search === null ? '' : trim($search);

        $matched = [];

        // Новые записи первыми: администратор приходит смотреть «что случилось
        // только что», а не начало файла.
        foreach (array_reverse($entries) as $entry) {
            if ($wanted !== [] && ! in_array($entry['level'], $wanted, true)) {
                continue;
            }

            if ($needle !== '' && ! $this->matches($entry, $needle)) {
                continue;
            }

            $matched[] = $entry;
        }

        return [
            'file' => basename($path),
            'entries' => array_slice($matched, $offset, $limit),
            'matched' => count($matched),
            'total_entries' => count($entries),
            'limit' => $limit,
            'offset' => $offset,
            'truncated' => $this->isTruncated($path),
            'levels_present' => $levelsPresent,
        ];
    }

    /**
     * Разобрать текст файла в записи.
     *
     * Строка, не похожая на заголовок, приклеивается к предыдущей записи:
     * это либо стектрейс, либо продолжение JSON-контекста, разбитого по
     * строкам. Строки, идущие ДО первого заголовка (хвост обрезанной записи
     * на границе сканирования), отбрасываются — притвориться, что это
     * отдельная запись, значит показать дату, которой у неё нет.
     *
     * @return list<array<string, mixed>>
     */
    private function parse(string $text): array
    {
        $entries = [];
        $current = null;

        /*
         * Разбиение по `\r\n|\n|\r`, а НЕ по `\R`.
         *
         * `\R` в PCRE без модификатора `u` считает переводом строки байт 0x85
         * (NEL) и 0x0B/0x0C. В UTF-8 байт 0x85 — это ВТОРОЙ байт кириллических
         * букв: «х» = D1 85, «Х» = D0 85. То есть строка «Вход в кабинет»
         * разрезалась на «В» + «од в кабинет», и запись выглядела как битая:
         * сообщение из двух байт и «стектрейс» из остатка текста. Проверено на
         * реальной записи. Явные \n и \r такой ошибки не дают: эти байты в
         * UTF-8 не встречаются внутри символа.
         */
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match(self::HEADER, $line, $m) === 1) {
                if ($current !== null) {
                    $entries[] = $this->finish($current);
                }

                $current = [
                    'date' => $m['date'],
                    'environment' => $m['env'],
                    'level' => $m['level'],
                    'message' => $m['message'],
                    'extra' => [],
                ];

                continue;
            }

            if ($current === null) {
                continue;
            }

            $current['extra'][] = $line;
        }

        if ($current !== null) {
            $entries[] = $this->finish($current);
        }

        return $entries;
    }

    /**
     * Дособрать запись: отделить JSON-контекст от стектрейса.
     *
     * Контекст Laravel дописывает в ту же строку, что и сообщение
     * (`... 504). {"userId":398,"exception":{...}}`), а `RedactSensitiveData`
     * добавляет к нему ещё один объект — `{"request_id":"..."}`. Поэтому
     * граница ищется как первый ` {"`, а НЕ попыткой разобрать хвост целиком:
     * два объекта подряд — валидный лог, но невалидный JSON, и разбор
     * «целиком» молча оставлял бы контекст внутри сообщения.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function finish(array $entry): array
    {
        $extra = implode("\n", $entry['extra']);
        $message = $entry['message'];
        $context = '';

        $jsonStart = strpos($message, ' {"');

        if ($jsonStart !== false && str_ends_with(rtrim($message), '}')) {
            $context = trim(substr($message, $jsonStart + 1));
            $message = rtrim(substr($message, 0, $jsonStart));
        }

        if ($context === '' && $extra !== '' && str_starts_with(ltrim($extra), '{')) {
            $context = trim($extra);
            $extra = '';
        }

        return [
            'date' => $entry['date'],
            'environment' => $entry['environment'],
            'level' => $entry['level'],
            'message' => $this->redactor->scrubText($message),
            // Контекст и трасса — `scrubJsonText`: в них лежат пары
            // `"password":"..."`, которые по значению не отличить от текста,
            // а по ключу — можно. Форматирование идёт ДО маскирования: после
            // него JSON уже не разбирается.
            'context' => $this->redactor->scrubJsonText($this->pretty($context)),
            'trace' => $extra === '' ? '' : $this->redactor->scrubJsonText($extra),
            'request_id' => $this->requestId($context),
        ];
    }

    /**
     * Разложить контекст по строкам, чтобы он читался.
     *
     * В логе контекст — одна длинная строка на несколько килобайт: стектрейс
     * исключения внутри `"exception"` занимает экран и не даёт найти нужное
     * поле. Разбор идёт по ОБЪЕКТАМ верхнего уровня, потому что `RedactSensitiveData`
     * дописывает к контексту свой объект (`{"request_id":"..."}`) — два объекта
     * подряд это валидный лог, но невалидный JSON, и `json_decode` по всей
     * строке вернул бы `null`.
     */
    private function pretty(string $context): string
    {
        if ($context === '') {
            return '';
        }

        $parts = $this->splitTopLevelObjects($context);

        if ($parts === []) {
            return $context;
        }

        $pretty = [];

        foreach ($parts as $part) {
            $decoded = json_decode($part, true);
            $pretty[] = is_array($decoded)
                ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $part;
        }

        return implode("\n", $pretty);
    }

    /**
     * Разбить текст на объекты верхнего уровня.
     *
     * Скобки внутри строковых значений не считаются: в сообщении исключения
     * легко встретить `{` в тексте, и наивный подсчёт разрезал бы объект
     * посередине.
     *
     * @return list<string>
     */
    private function splitTopLevelObjects(string $text): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $inString = false;
        $escaped = false;

        foreach (str_split($text) as $char) {
            if ($inString) {
                $buffer .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $buffer .= $char;
                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;
                $buffer .= $char;
                continue;
            }

            if ($char === '}' || $char === ']') {
                $depth--;
                $buffer .= $char;

                if ($depth === 0) {
                    $parts[] = trim($buffer);
                    $buffer = '';
                }

                continue;
            }

            // Мусор между объектами (пробел, запятая) не копируется, пока
            // объект не начался: иначе первый «объект» был бы пустой строкой.
            if ($depth > 0) {
                $buffer .= $char;
            }
        }

        return $parts;
    }

    /**
     * Идентификатор запроса из контекста — по нему поддержка находит все
     * записи одного обращения, включая те, что остались в других файлах.
     */
    private function requestId(string $context): ?string
    {
        if ($context === '') {
            return null;
        }

        return preg_match('/"request_id"\s*:\s*"([A-Za-z0-9._-]{4,64})"/', $context, $m) === 1 ? $m[1] : null;
    }

    /** @param array<string, mixed> $entry */
    private function matches(array $entry, string $needle): bool
    {
        $haystack = $entry['message'] . "\n" . $entry['context'] . "\n" . $entry['trace'];

        return mb_stripos($haystack, $needle) !== false;
    }

    /** Имя файла из запроса → путь, только если он есть в списке доступных. */
    private function resolve(?string $file): ?string
    {
        if ($file === null || $file === '') {
            return $this->newestPath();
        }

        // basename() отсекает `../`, а сверка с реальным списком — всё
        // остальное: подставить сюда имя чужого файла из storage нельзя.
        $name = basename($file);

        foreach ($this->paths() as $path) {
            if (basename($path) === $name) {
                return $path;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function paths(): array
    {
        $paths = glob(storage_path('logs/*.log')) ?: [];

        // Только логи приложения: `laravel*.log` покрывает daily-файлы и
        // `laravel.log`, но не служебные файлы сторонних пакетов.
        return array_values(array_filter(
            $paths,
            static fn (string $path): bool => (bool) preg_match('/^laravel[\w.\-]*\.log$/', basename($path))
        ));
    }

    private function newestPath(): ?string
    {
        $newest = null;
        $newestAt = -1;

        foreach ($this->paths() as $path) {
            $at = @filemtime($path);
            if ($at !== false && $at > $newestAt) {
                $newestAt = $at;
                $newest = $path;
            }
        }

        return $newest;
    }

    private function isTruncated(string $path): bool
    {
        return (int) (@filesize($path) ?: 0) > self::TAIL_BYTES;
    }

    /**
     * Хвост файла целиком в память, начиная с целой строки.
     *
     * Файл читается через `SplFileObject::fseek`, а не `file_get_contents`:
     * так в память попадает только нужное окно, даже если файл гигабайтный.
     */
    private function tail(string $path): string
    {
        $size = (int) (@filesize($path) ?: 0);

        if ($size === 0) {
            return '';
        }

        $start = max(0, $size - self::TAIL_BYTES);

        $file = new SplFileObject($path, 'r');
        $file->fseek($start);
        $text = (string) $file->fread(self::TAIL_BYTES);
        $file = null;

        if ($start > 0) {
            $firstBreak = strpos($text, "\n");
            $text = $firstBreak === false ? '' : substr($text, $firstBreak + 1);
        }

        return $text;
    }
}
