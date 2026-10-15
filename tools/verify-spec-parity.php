<?php

declare(strict_types=1);

/**
 * ПАРИТЕТ ДВУХ КОПИЙ OPENAPI: nabilet_core_spec (источник истины) ↔ docs/.
 *
 * Зачем этот файл существует
 *
 *   В репозитории две копии контракта: `nabilet_core_spec/openapi.yaml`
 *   (источник истины, на него пишутся спеки модулей) и `docs/openapi.yaml`
 *   (второй экземпляр для выдачи с документацией). `verify-openapi.php`
 *   проверяет каждый документ ПО ОТДЕЛЬНОСТИ — свою внутреннюю целостность, —
 *   и не сравнивает копии между собой. Из-за этого они молча разошлись:
 *
 *     • канон не описывал `getEventBySlug` (`GET /events/by-slug/{slug}`) и
 *       `metrikaConfig` (`GET /analytics/metrika/config`) — оба эндпоинта
 *       существовали в маршрутах с волны 1, но в источник истины не попали;
 *     • `docs/openapi.yaml` не описывал `extendGuestCartHold` (`POST /cart/extend`).
 *
 *   Копии — не побайтовый клон (порядок, оформление описаний могут отличаться),
 *   поэтому здесь сравниваются МНОЖЕСТВА, а не текст: набор operationId и набор
 *   имён компонентов каждого вида. Расхождение в любую сторону — падение: либо
 *   спека отстала от кода, либо вторая копия описывает несуществующее.
 *
 * Usage:
 *     php tools/verify-spec-parity.php
 *     php tools/verify-spec-parity.php <canonical.yaml> <mirror.yaml>
 *
 * Без аргументов сверяются копии репозитория. Пути как аргументы — для проверки
 * выправок копий отдельно от дерева (и для теста самого инструмента).
 *
 * Пути копий заданы ниже; окружение (Composer, БД, Laravel) не требуется.
 *
 * Чего этот инструмент НЕ делает: не проверяет покрытие маршрутов приложения
 * спека-документом (это отдельная задача с ратчетом намеренно недокументированных
 * путей) и не сравнивает содержимое операций между копиями — только паритет
 * покрытия, потому именно он дал тихий дрейф.
 */

const CANON = 'nabilet_core_spec/openapi.yaml';
const MIRROR = 'docs/openapi.yaml';

$canonRel = $argv[1] ?? CANON;
$mirrorRel = $argv[2] ?? MIRROR;

$root = dirname(__DIR__);

$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, callable $assertion): void
{
    global $passed, $failed, $failures;

    try {
        $assertion();
        $passed++;
        printf("  \u{2713} %s\n", $label);
    } catch (Throwable $e) {
        $failed++;
        $failures[] = $label . ' — ' . $e->getMessage();
        printf("  \u{2717} %s\n      %s\n", $label, $e->getMessage());
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertSameSets(array $expected, array $actual, string $what): void
{
    $missing = array_values(array_diff($expected, $actual));
    $extra = array_values(array_diff($actual, $expected));

    if ($missing === [] && $extra === []) {
        return;
    }

    $parts = [];
    if ($missing !== []) {
        $parts[] = 'нет в docs: ' . implode(', ', $missing);
    }
    if ($extra !== []) {
        $parts[] = 'лишние в docs: ' . implode(', ', $extra);
    }

    throw new RuntimeException(sprintf('%s расходится (%s)', $what, implode('; ', $parts)));
}

/**
 * Имена компонентов вида $kind (`schemas`, `responses`, `parameters`,
 * `securitySchemes`) — только непосредственные дети `components.<kind>`.
 *
 * Разбор построчный: у компонента имя на 4 пробелах, у его свойств — глубже,
 * поэтому свойство не спутать с именем.
 */
function componentNames(string $raw, string $kind): array
{
    $names = [];
    $inComponents = false;
    $inKind = false;

    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/^(\S.*)$/', $line) === 1) {
            $inComponents = trim($line) === 'components:';
            $inKind = false;
            continue;
        }

        if (! $inComponents) {
            continue;
        }

        if (preg_match('/^  ([A-Za-z0-9_]+):\s*$/', $line, $m) === 1) {
            $inKind = $m[1] === $kind;
            continue;
        }

        if ($inKind && preg_match('/^    ([A-Za-z0-9_]+):/', $line, $m) === 1) {
            $names[$m[1]] = true;
        }
    }

    $names = array_keys($names);
    sort($names);

    return $names;
}

function operationIds(string $raw): array
{
    preg_match_all('/^ +operationId: *([A-Za-z0-9_]+) *$/m', $raw, $m);
    $ids = array_values(array_unique($m[1]));
    sort($ids);

    return $ids;
}

function readContract(string $root, string $relative): string
{
    $path = $relative === CANON || $relative === MIRROR
        ? $root . '/' . $relative
        : $relative;

    if (! is_file($path)) {
        fwrite(STDERR, "OpenAPI copy not found: {$relative}\n");
        exit(1);
    }

    // Файлы лежат в CRLF: без нормализации хвостовой `\r` ломает `$` в
    // построчных регулярках. `verify-openapi.php` получает чистый текст из
    // `file(FILE_IGNORE_NEW_LINES)`; здесь нормализуем явно.
    $raw = (string) file_get_contents($path);
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    return $raw;
}

$canon = readContract($root, $canonRel);
$mirror = readContract($root, $mirrorRel);

echo "NABILET Core — паритет копий OpenAPI (" . basename($canonRel) . " \u{2194} " . basename($mirrorRel) . ")\n";
echo str_repeat("\u{2500}", 74) . "\n\n";

$canonOps = operationIds($canon);
$mirrorOps = operationIds($mirror);

printf("  операций: канон %d, docs %d \u{00b7} схем: %d/%d \u{00b7} ответов: %d/%d \u{00b7} параметров: %d/%d\n\n",
    count($canonOps), count($mirrorOps),
    count(componentNames($canon, 'schemas')), count(componentNames($mirror, 'schemas')),
    count(componentNames($canon, 'responses')), count(componentNames($mirror, 'responses')),
    count(componentNames($canon, 'parameters')), count(componentNames($mirror, 'parameters'))
);

check('оба документа объявляют info, paths и components', function () use ($canon, $mirror): void {
    foreach (['canon' => $canon, 'docs' => $mirror] as $who => $raw) {
        foreach (['openapi:', 'paths:', 'components:', 'info:'] as $needle) {
            assertTrue(
                str_contains("\n" . $raw, "\n" . $needle),
                $who . ' не объявляет ' . rtrim($needle, ':')
            );
        }
    }
});

check('наборы operationId совпадают', function () use ($canonOps, $mirrorOps): void {
    assertSameSets($canonOps, $mirrorOps, 'operations');
});

check('наборы schemas совпадают', function () use ($canon, $mirror): void {
    assertSameSets(componentNames($canon, 'schemas'), componentNames($mirror, 'schemas'), 'components.schemas');
});

check('наборы responses совпадают', function () use ($canon, $mirror): void {
    assertSameSets(componentNames($canon, 'responses'), componentNames($mirror, 'responses'), 'components.responses');
});

check('наборы parameters совпадают', function () use ($canon, $mirror): void {
    assertSameSets(componentNames($canon, 'parameters'), componentNames($mirror, 'parameters'), 'components.parameters');
});

check('наборы securitySchemes совпадают', function () use ($canon, $mirror): void {
    assertSameSets(componentNames($canon, 'securitySchemes'), componentNames($mirror, 'securitySchemes'), 'components.securitySchemes');
});

echo "\n";

if ($failed === 0) {
    printf("PASS  %d checks passed, 0 failed  (%d operations in both copies)\n", $passed, count($canonOps));
    exit(0);
}

echo "Failures:\n";
foreach ($failures as $i => $failure) {
    printf("  %d) %s\n", $i + 1, $failure);
}

printf("FAIL  %d checks passed, %d failed\n", $passed, $failed);
exit(1);
