<?php

declare(strict_types=1);

/**
 * Конвертер hallplan Яндекс.Афиши → схема конструктора NABILET.
 *
 * ВХОД  — ответ `GET /api/tickets/v4/sessions/<b64>/hallplan` (поloный
 * envelop `{status,result:{hallplan:…}}` или сам `hallplan`); лежит в
 * Downloads после снятия в DevTools (Procedure: docs/YANDEX-AFISHA-HALLPLAN.md §5.1).
 *
 * ВЫХОД — `schema.json` в формате редактора зала (schema_json): принимает
 * «Импорт» конструктора и HallService::validateSchemaPayload. Публикация —
 * всегда новой версией (published immutable).
 *
 * ЧТО ВАЖНО ЗНАТЬ О ФОРМАТЕ ЯНДЕКСА
 *   - `levels[].seats[]` содержит ТОЛЬКО ПРОДАВАЕМЫЕ места (163 из 512 на
 *     реальном примере). Полная геометрия — в `levels[].rows[]`:
 *     номер ряда, число мест, координата первого места.
 *   - Поэтому места, которых нет в `seats[]` (проданы/заблокированы),
 *     восстанавливаются интерполяцией по анкерам ряда; ряд без единого
 *     анкера переносится с ближайшего ряда, у которого геометрия есть
 *     (см. Фаза 2 ниже), а `levels[].outline` работает арбитром формы зала.
 *   - Цены приходят в КОПЕЙКАХ на место (`priceInfo.price.value`);
 *     сервисный сбор (`fee`) в схему не переносится.
 *   - Протокол продавца бывает дистанционным (Ticketsteam / «ЯБилеты ARM» —
 *           касса Югра): `gatewaySessionId` — id события у продавца.
 */
$argv = $argv ?? [];

if (PHP_SAPI !== 'cli' || count($argv) < 2) {
    fwrite(STDERR, "usage: php " . basename(__FILE__) . " hallplan.json [--out schema.json] [--stage]\n"
        . "       [--hall <publicId> [--draft <versionId>] [--publish]] — черновик (и публикация) зала\n");
    exit(1);
}

$in = $argv[1];
$outArgIdx = array_search('--out', $argv, true);
$out = $outArgIdx !== false && isset($argv[$outArgIdx + 1]) ? $argv[$outArgIdx + 1] : 'schema.json';
$hallArgIdx = array_search('--hall', $argv, true);
$hallPublicId = $hallArgIdx !== false && isset($argv[$hallArgIdx + 1]) ? $argv[$hallArgIdx + 1] : null;
$draftArgIdx = array_search('--draft', $argv, true);
$draftVersionId = $draftArgIdx !== false && isset($argv[$draftArgIdx + 1]) ? (int) $argv[$draftArgIdx + 1] : null;
$publish = in_array('--publish', $argv, true);
$withStage = in_array('--stage', $argv, true);

if (!is_file($in)) {
    fwrite(STDERR, "файл не найден: {$in}\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($in), true);
if (!is_array($payload)) {
    fwrite(STDERR, "не JSON: {$in}\n");
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
try {
    $schema = (new Nabilet\Modules\Venues\Halls\Domain\YandexHallplanConverter())->convert($payload, $withStage);
} catch (\InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
$sectors = $schema['sectors'];

file_put_contents($out, json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$places = array_sum(array_map(static fn (array $s): int => count($s['seats']), $sectors));
echo "sectors: " . count($sectors) . ", мест: {$places}\n";
echo "canvas: {$schema['canvas']['width']}x{$schema['canvas']['height']}\n";
echo "out: {$out}\n";

// ── Опционально: сразу черновик (+ публикация) у конкретного зала ─────
// Одним шагом из JSON до published-версии. Черновик создаётся так же, как
// сидеры залов (createSchemaDraft → publishSchemaVersion), правки редактора
// не затрагиваются: published всегда новая версия.
if ($hallPublicId !== null) {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $app = require dirname(__DIR__) . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $hall = Nabilet\Modules\Venues\Models\Hall::query()->where('public_id', $hallPublicId)->first();
    if ($hall === null) {
        fwrite(STDERR, "зал не найден по public_id: {$hallPublicId}\n");
        exit(1);
    }

    $service = app(Nabilet\Modules\Venues\Halls\Services\HallService::class);
    $userId = (int) (Illuminate\Support\Facades\DB::table('users')->orderBy('id')->value('id') ?? 1);

    // --draft <id>: перезаписать уже созданный черновик (правки конвертера
    // повторяются без ручного удаления версии). Черновик обязан быть свежим:
    // ревизия читается здесь же, чтобы не затереть правки редактора.
    $version = $draftVersionId !== null
        ? $service->createSchemaDraft(
            $hall,
            $schema,
            $userId,
            $draftVersionId,
            (int) (Illuminate\Support\Facades\DB::table('hall_schema_versions')->where('id', $draftVersionId)->value('revision') ?? 1),
        )
        : $service->createSchemaDraft($hall, $schema, $userId);

    if ($publish) {
        $service->publishSchemaVersion((int) $version->id, $userId, (string) $hall->public_id);
        echo "hall {$hall->public_id} «{$hall->name}»: v{$version->version} ОПУБЛИКОВАНА (version id {$version->id})\n";
    } else {
        echo "hall {$hall->public_id} «{$hall->name}»: черновик v{$version->version} создан — проверьте в конструкторе и нажмите «Опубликовать»\n";
    }
} else {
    echo "Дальше: конструктор → «Импорт» → сверить → «Опубликовать» (новой версией).\n";
}
