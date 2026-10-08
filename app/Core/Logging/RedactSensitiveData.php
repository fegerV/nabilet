<?php

declare(strict_types=1);

namespace Nabilet\Core\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Маскирование персональных данных в логах (152-ФЗ).
 *
 * ЗАЧЕМ
 *   Логи — это хранилище персональных данных, о котором чаще всего забывают.
 *   `docs/SERVER-HEALTH.md` (§9) фиксирует, что в лог попадали сообщения,
 *   называющие колонки и значения, то есть e-mail, телефоны и токены
 *   покупателей оказывались в `storage/logs/*.log` в открытом виде, хранились
 *   14 дней (`daily.days`) и уезжали в `security.log` на 90 дней. Это
 *   одновременно и утечка, и нарушение принципа минимизации: лог нужен для
 *   отладки, а не как вторая копия базы.
 *
 * ПОЧЕМУ ПРОЦЕССОР MONOLOG, А НЕ ПРАВКА МЕСТ ЛОГИРОВАНИЯ
 *   Точечная правка `Log::error(...)` не работает как защита: она закрывает
 *   те вызовы, о которых вспомнили. Персональные данные попадают в лог и
 *   косвенно — через текст `QueryException`, где значения уже подставлены в
 *   SQL драйвером, и через `$context`, собранный в чужом модуле. Процессор
 *   стоит на выходе, видит каждую запись любого канала и не даёт забыть.
 *
 * ЧЕТЫРЕ КАНАЛА УТЕЧКИ, КОТОРЫЕ ЗДЕСЬ ЗАКРЫТЫ
 *   1. `$context` по ключу: `['email' => ..., 'password' => ...]`.
 *   2. `$context` по значению: адрес внутри произвольной строки.
 *   3. ИСКЛЮЧЕНИЕ В `$context`. Laravel включает трассировку
 *      (`LogManager::formatter()` → `new LineFormatter(..., includeStacktraces:
 *      true)`), а `getTraceAsString()` в PHP печатает АРГУМЕНТЫ вызовов.
 *      Проверено на этом рантайме: `login('user@example.co...', 'hunter2')` —
 *      то есть адрес и пароль покупателя попадали в лог целиком, минуя любую
 *      фильтрацию строк, потому что фильтровалась только `message`.
 *      Поэтому `\Throwable` здесь разбирается на части вручную, а трасса
 *      строится из `getTrace()` (только `файл:строка`, без аргументов) —
 *      так же, как это делает `Monolog\Formatter\NormalizerFormatter`.
 *   4. СТРОКОВОЕ ПРЕДСТАВЛЕНИЕ ИСКЛЮЧЕНИЯ в `message` (`Log::error($e)`):
 *      `Exception::__toString()` тоже включает трассу, уже вклеенную в
 *      строку вместе с аргументами. Их срезает `stripTraceArguments()`.
 *
 * ГЛАВНЫЙ РИСК ЭТОГО ФИЛЬТРА — ЛОЖНЫЕ СРАБАТЫВАНИЯ
 *   Фильтр, который прячет половину лога, отключают — и тогда он не защищает
 *   ничего. Поэтому проверки значений намеренно узкие, и там, где формат
 *   неотличим от обычных данных, маскирование НЕ делается:
 *
 *   - ИНН и СНИЛС без разделителей (10–12 цифр) неотличимы от идентификатора
 *     заказа или Unix-времени, поэтому по значению не маскируются — только по
 *     ключу `inn`/`snils`. СНИЛС в каноничной форме `XXX-XXX-XXX YY`
 *     маскируется: у идентификаторов такой формы не бывает.
 *   - Номер карты маскируется, только если он проходит проверку Луна. Это
 *     убирает почти все ложные срабатывания на длинных числовых id.
 *   - Телефон вида `8XXXXXXXXXX` (без разделителей) не маскируется: под него
 *     попадает любой 11-значный id, начинающийся с восьми. Маскируются `+7`
 *     в любом написании и `8` с разделителями.
 *   - Unix-время (10 цифр) не маскируется никогда — оно есть в каждом
 *     втором сообщении, и его потеря сделала бы логи бесполезными.
 *
 *   Это осознанный компромисс: полнота маскирования принесена в жертву тому,
 *   чтобы фильтр не выключили. Ключевой маскировки для `inn`/`snils`
 *   достаточно, потому что в `$context` они приходят под своим именем.
 *
 * ЧЕГО ЗДЕСЬ СОЗНАТЕЛЬНО НЕТ
 *   Маскирование имён и адресов: у них нет формата, по которому их можно
 *   отличить от обычного текста, а эвристика «всё после слова `name`» ломает
 *   диагностику. Для этих полей защита — не логировать их в `$context`, и это
 *   ответственность вызывающего кода, а не фильтра.
 *
 * ВАЖНО ПРО ОТЛАДКУ
 *   Маскирование НЕОБРАТИМО, и это намеренно. Лог с обратимо зашифрованными
 *   данными остаётся хранилищем персональных данных — просто под ключом,
 *   который лежит рядом. Если для разбора нужен конкретный адрес, его берут
 *   из базы по `order_id`, а не из лога.
 *
 * ПОЧЕМУ РЕАЛИЗОВАН `Monolog\Processor\ProcessorInterface`
 *   Не косметика: `LogManager::createMonologDriver()` проверяет каждый
 *   процессор через `is_a($processor, ProcessorInterface::class, true)` и
 *   бросает `InvalidArgumentException` на всё остальное. Вызываемый объект без
 *   интерфейса валит создание канала, Laravel уходит в «emergency logger» и
 *   пишет в `laravel.log` без фильтрации — то есть попытка включить защиту
 *   оставляла логи и без защиты, и без канала. Проверено на живой установке.
 */
final class RedactSensitiveData implements ProcessorInterface
{
    public const MASK = '[redacted]';

    /**
     * Фрагменты имён ключей, значение которых маскируется целиком.
     * Сравнение регистронезависимое, вхождение в имя ключа.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_PARTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'auth_header',
        'cookie',
        'remember',
        'email',
        'phone',
        'msisdn',
        'card',
        'pan',
        'cvv',
        'cvc',
        'iban',
        'passport',
        'snils',
        'inn',
        'birth',
        'signature',
        'private_key',
    ];

    /**
     * Значения этих ключей остаются как есть, хотя имя похоже на
     * чувствительное: `email_verified_at` — дата, `card_last4` — уже
     * обрезанный номер. Маска скрыла бы факт, который как раз и нужен для
     * диагностики, а данные не защитила бы.
     *
     * @var list<string>
     */
    private const SAFE_KEYS = [
        'email_verified_at',
        'card_last4',
        'token_type',
        'token_expires_at',
    ];

    /** Максимальная глубина обхода контекста: защита от циклов и от рекурсии. */
    private const MAX_DEPTH = 6;

    /** Сколько кадров трассы сохранять. */
    private const MAX_TRACE_FRAMES = 25;

    private const EMAIL_PATTERN = '/\b([A-Za-z0-9._%+\-]{1,64})@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})\b/u';

    /** СНИЛС в каноничной форме: `123-456-789 00`. */
    private const SNILS_PATTERN = '/\b\d{3}-\d{3}-\d{3}\s\d{2}\b/u';

    /** `+7` в любом написании: разделители необязательны. */
    private const PHONE_PLUS7_PATTERN = '/\+7[\s\-\(]*\d{3}[\s\-\)]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}/u';

    /** `8` только с разделителями — иначе под шаблон попадёт любой 11-значный id. */
    private const PHONE_EIGHT_PATTERN = '/\b8[\s\-\(]+\d{3}[\s\-\)]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}/u';

    /** 13–19 цифр, допускаются пробелы и дефисы группами. Проверяется по Луну. */
    private const CARD_PATTERN = '/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{1,7}\b/u';

    /** Строка кадра трассы PHP: `#0 /path/file.php(42): func(...)`. */
    private const TRACE_FRAME_PATTERN = '/^#\d+ .*$/m';

    /**
     * Свойства `Monolog\LogRecord` объявлены `readonly`, поэтому запись
     * собирается заново через `with()`. Прямое присваивание
     * (`$record->message = ...`) — фатал «Cannot modify readonly property».
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->scrubString($record->message),
            context: $this->scrubArray($record->context),
            extra: $this->scrubArray($record->extra),
        );
    }

    /**
     * Маскирование готовой строки — для тех, кто отдаёт лог наружу, минуя
     * запись. Так делает админский просмотр логов (`SystemStatusController::
     * viewLogs`): файл на диске уже прошёл фильтр, но в нём лежат записи,
     * сделанные ДО того, как фильтр появился, и отдавать их как есть — значит
     * воспроизводить ту самую утечку через админку.
     */
    public function scrubText(string $text): string
    {
        return $this->scrubString($text);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function scrubArray(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::MASK;
                continue;
            }

            if ($value instanceof \Throwable) {
                $data[$key] = $this->normalizeThrowable($value, $depth);
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->scrubArray($value, $depth + 1);
                continue;
            }

            if (is_string($value)) {
                $data[$key] = $this->scrubString($value);
                continue;
            }

            // Прочие объекты не обходим: их строковое представление всё равно
            // пройдёт через форматтер канала, а обход здесь дёрнул бы
            // `__toString()` у модели и вытащил её атрибуты целиком.
            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * Разбор исключения в структуру БЕЗ аргументов вызовов.
     *
     * Форма намеренно повторяет `Monolog\Formatter\NormalizerFormatter::
     * normalizeException()`: `class` / `message` / `code` / `file` / `trace` /
     * `previous`. Так запись в логе выглядит как раньше, но:
     *   - `message` замаскирован (там бывают значения, подставленные в SQL);
     *   - `trace` собран из `getTrace()` как `файл:строка`, то есть БЕЗ
     *     аргументов, тогда как `getTraceAsString()` их печатает.
     *
     * Именно поэтому `\Throwable` не оставлен объектом: форматтер
     * `LineFormatter` (Laravel создаёт его с `includeStacktraces: true`)
     * отдал бы исключение в `getTraceAsString()` — вместе с аргументами.
     * Проверено на этом рантайме: `login('user@example.co...', 'hunter2')` —
     * адрес и пароль покупателя целиком.
     *
     * @return array<string, mixed>
     */
    private function normalizeThrowable(\Throwable $e, int $depth = 0): array
    {
        $data = [
            'class' => $e::class,
            'message' => $this->scrubString($e->getMessage()),
            'code' => (int) $e->getCode(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ];

        foreach (array_slice($e->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            if (isset($frame['file'])) {
                $data['trace'][] = $frame['file'] . ':' . ($frame['line'] ?? 0);
            }
        }

        $previous = $e->getPrevious();
        if ($previous instanceof \Throwable && $depth < self::MAX_DEPTH) {
            $data['previous'] = $this->normalizeThrowable($previous, $depth + 1);
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        if (in_array($normalized, self::SAFE_KEYS, true)) {
            return false;
        }

        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($normalized, $part)) {
                return true;
            }
        }

        return false;
    }

    private function scrubString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $value = $this->stripTraceArguments($value);

        // Домен оставляем: он полезен для диагностики («письмо ушло не на тот
        // домен»), а личность раскрывает именно локальная часть.
        $value = preg_replace_callback(
            self::EMAIL_PATTERN,
            static fn (array $m): string => $m[1][0] . '***@' . $m[2],
            $value
        ) ?? $value;

        $value = preg_replace(self::SNILS_PATTERN, self::MASK, $value) ?? $value;
        $value = preg_replace(self::PHONE_PLUS7_PATTERN, self::MASK, $value) ?? $value;
        $value = preg_replace(self::PHONE_EIGHT_PATTERN, self::MASK, $value) ?? $value;

        return preg_replace_callback(
            self::CARD_PATTERN,
            fn (array $m): string => $this->looksLikeCard($m[0]) ? self::MASK : $m[0],
            $value
        ) ?? $value;
    }

    /**
     * Удаление аргументов из кадров трассы.
     *
     * `Exception::__toString()` вклеивает `getTraceAsString()` прямо в строку
     * сообщения, поэтому маскировать по значениям её недостаточно: аргументом
     * может быть что угодно (`'hunter2'`), а не только то, что похоже на
     * адрес или телефон. Режется всё после последней `(` в кадре — включая
     * вложенные `Object(...)`. Это умышленно грубо: отделить полезный
     * аргумент от персонального нельзя, а кадр и без аргументов остаётся
     * полезным — `файл:строка` и имя функции на месте.
     */
    private function stripTraceArguments(string $value): string
    {
        if (! str_contains($value, '#')) {
            return $value;
        }

        return preg_replace_callback(
            self::TRACE_FRAME_PATTERN,
            static function (array $m): string {
                $line = $m[0];
                $open = strrpos($line, '(');

                if ($open === false || ! str_ends_with($line, ')')) {
                    return $line;
                }

                return substr($line, 0, $open) . '(...)';
            },
            $value
        ) ?? $value;
    }

    /**
     * Проверка Луна. Нужна не для валидации карты как таковой, а чтобы не
     * маскировать 16-значные идентификаторы: случайные цифры проходят Луна
     * примерно в одном случае из десяти, тогда как настоящий номер карты —
     * всегда.
     */
    private function looksLikeCard(string $candidate): bool
    {
        $digits = preg_replace('/\D/', '', $candidate) ?? '';

        $length = strlen($digits);
        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
