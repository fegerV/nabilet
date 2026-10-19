<?php

declare(strict_types=1);

/**
 * Ценовая лесенца для «Вавилона»: чем ближе стол к сцене, тем дороже.
 *
 * ЗАЧЕМ. В банкетном зале цена зависит от близости к сцене, а руками вбивать
 * 52 стола нереально. Инструмент считает цену каждого стола по его координатам
 * в схеме зала, группирует столы в `--tiers` уровней и (по флагу) применяет их
 * к инвентарю КОНКРЕТНОГО сеанса.
 *
 * ОТКУДА КООРДИНАТЫ. Из `schema_json` (канвас-формат редактора): у сектора
 * `shape: 'table'` места лежат в `seats[]` (или `rows[].seats[]`), а смещение
 * сектора — в `sector.x/sector.y`. Центр стола = среднее координат его мест в
 * пространстве холста; расстояние — до «фронта сцены».
 *
 * ГДЕ СЦЕНА. В схеме «Вавилона» объекта сцены нет, поэтому по умолчанию фронт
 * берётся из прямоугольников танцпола (`staticObjects` kind=standing) — столы
 * у танцпола и есть столы у сцены. Точку можно переопределить `--stage-x/--stage-y`.
 *
 * ЕДИНИЦЫ. Цены — в минорных единицах (копейках), как и `inventory_items.price_amount`.
 * Аргументы `--min/--max/--floor` задаются в РУБЛЯХ и умножаются на 100.
 *
 * ПРИМЕНЕНИЕ. Без `--apply` — только предпросмотр. С `--apply --session <id>`
 * зовёт `InventoryService::updateRowPrices()`. По умолчанию `session_only=true`:
 * базовая сетка зала (hall_rows / standing_zones) НЕ перезаписывается, поэтому
 * будущие сеансы этого зала унаследуют цены схемы, а не эту лесенницу.
 *
 * usage:
 *   php tools/vavilon-price-ladder.php --hall <publicId> [--tiers 5] [--min 3000] [--max 6000]
 *   php tools/vavilon-price-ladder.php --schema <versionId> --session <id> --apply
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Inventory\Services\InventoryService;
use Nabilet\Modules\Sessions\Models\Session;

/* ── аргументы ───────────────────────────────────────────────────────────── */

$argv = $argv ?? [];
$opt = static function (string $name) use ($argv): ?string {
    $i = array_search('--' . $name, $argv, true);
    if ($i === false || !isset($argv[$i + 1]) || str_starts_with((string) $argv[$i + 1], '--')) {
        return null;
    }
    return (string) $argv[$i + 1];
};
$has = static fn (string $name): bool => in_array('--' . $name, $argv, true);

$schemaIdArg = $opt('schema') !== null ? (int) $opt('schema') : null;
$hallArg = $opt('hall');
$sessionArg = $opt('session');
$tiers = max(2, (int) ($opt('tiers') ?? 5));
$minRub = (float) ($opt('min') ?? 3000);
$maxRub = (float) ($opt('max') ?? 6000);
$floorRub = (float) ($opt('floor') ?? 1500);
$stageX = $opt('stage-x') !== null ? (float) $opt('stage-x') : null;
$stageY = $opt('stage-y') !== null ? (float) $opt('stage-y') : null;
$outFile = $opt('out');
$apply = $has('apply');
$touchSchema = $has('touch-schema');

if ($minRub > $maxRub) {
    fwrite(STDERR, "! --min не может быть больше --max\n");
    exit(1);
}

/* ── резолвим версию схемы ───────────────────────────────────────────────── */

$session = null;
if ($sessionArg !== null) {
    $session = is_numeric($sessionArg)
        ? Session::find((int) $sessionArg)
        : Session::where('public_id', $sessionArg)->first();
    if ($session === null) {
        fwrite(STDERR, "! сеанс не найден: {$sessionArg}\n");
        exit(1);
    }
}

if ($schemaIdArg !== null) {
    $svId = $schemaIdArg;
} elseif ($session !== null) {
    $svId = (int) $session->schema_version_id;
} elseif ($hallArg !== null) {
    $hall = DB::table('halls')->where('public_id', $hallArg)->first();
    if ($hall === null) {
        fwrite(STDERR, "! зал не найден: {$hallArg}\n");
        exit(1);
    }
    $svId = (int) DB::table('hall_schema_versions')
        ->where('hall_id', $hall->id)
        ->where('status', 'published')
        ->orderByDesc('id')
        ->value('id');
    if ($svId === 0) {
        fwrite(STDERR, "! у зала нет опубликованной схемы\n");
        exit(1);
    }
} else {
    fwrite(STDERR, "укажите --hall, --schema или --session\n");
    exit(1);
}

$sv = DB::table('hall_schema_versions')->where('id', $svId)->first();
if ($sv === null) {
    fwrite(STDERR, "! версия схемы не найдена: {$svId}\n");
    exit(1);
}
$json = json_decode((string) $sv->schema_json, true);
if (!is_array($json)) {
    fwrite(STDERR, "! schema_json не разбирается\n");
    exit(1);
}

/* ── собираем столы и танцпол ────────────────────────────────────────────── */

$collectSeats = static function (array $sector): array {
    $ox = (float) ($sector['x'] ?? 0);
    $oy = (float) ($sector['y'] ?? 0);
    $pts = [];
    if (!empty($sector['seats']) && is_array($sector['seats'])) {
        foreach ($sector['seats'] as $seat) {
            if (is_array($seat)) {
                $pts[] = [(float) ($seat['x'] ?? 0) + $ox, (float) ($seat['y'] ?? 0) + $oy];
            }
        }
    }
    if (!empty($sector['rows']) && is_array($sector['rows'])) {
        foreach ($sector['rows'] as $row) {
            foreach ($row['seats'] ?? [] as $seat) {
                if (is_array($seat)) {
                    $pts[] = [(float) ($seat['x'] ?? 0) + $ox, (float) ($seat['y'] ?? 0) + $oy];
                }
            }
        }
    }
    return $pts;
};

$tables = [];
foreach ($json['sectors'] ?? [] as $sector) {
    if (!is_array($sector) || ($sector['shape'] ?? null) !== 'table') {
        continue;
    }
    $pts = $collectSeats($sector);
    if ($pts !== []) {
        $cx = array_sum(array_column($pts, 0)) / count($pts);
        $cy = array_sum(array_column($pts, 1)) / count($pts);
    } else {
        $cx = (float) ($sector['x'] ?? 0);
        $cy = (float) ($sector['y'] ?? 0);
    }
    $tables[] = [
        'name' => (string) ($sector['name'] ?? 'Стол'),
        'x' => $cx,
        'y' => $cy,
        'seats' => count($pts),
        'table' => $sector['table'] ?? null,
    ];
}

if ($tables === []) {
    fwrite(STDERR, "! в схеме нет секторов shape=table\n");
    exit(1);
}

$floors = [];
foreach ($json['staticObjects'] ?? [] as $obj) {
    if (!is_array($obj) || ($obj['kind'] ?? '') !== 'standing') {
        continue;
    }
    $floors[] = [
        'name' => (string) ($obj['text'] ?? 'Танцпол'),
        'x' => (float) ($obj['x'] ?? 0),
        'y' => (float) ($obj['y'] ?? 0),
        'capacity' => (int) ($obj['capacity'] ?? 0),
    ];
}

/* ── точка «фронта сцены» ────────────────────────────────────────────────── */

if ($stageX !== null && $stageY !== null) {
    $ref = ['x' => $stageX, 'y' => $stageY, 'how' => 'задано вручную'];
} elseif ($opt('ref') === 'centroid') {
    $ref = [
        'x' => array_sum(array_column($tables, 'x')) / count($tables),
        'y' => array_sum(array_column($tables, 'y')) / count($tables),
        'how' => 'центроид столов',
    ];
} else {
    if ($floors !== []) {
        $w = array_sum(array_map(static fn (array $f): int => max(1, $f['capacity']), $floors));
        $ref = [
            'x' => array_sum(array_map(static fn (array $f): float => $f['x'] * max(1, $f['capacity']), $floors)) / $w,
            'y' => array_sum(array_map(static fn (array $f): float => $f['y'] * max(1, $f['capacity']), $floors)) / $w,
            'how' => 'фронт танцпола (вес = вместимость)',
        ];
    } else {
        $ref = ['x' => 0.0, 'y' => 0.0, 'how' => 'угол холста (0,0) — танцпола в схеме нет'];
    }
}

/* ── расстояния и тиры ───────────────────────────────────────────────────── */

foreach ($tables as &$t) {
    $t['d'] = hypot($t['x'] - $ref['x'], $t['y'] - $ref['y']);
}
unset($t);

$byDistAsc = $tables;
usort($byDistAsc, static fn (array $a, array $b): int => $a['d'] <=> $b['d']);

$minK = (int) round($minRub * 100);
$maxK = (int) round($maxRub * 100);
$n = count($byDistAsc);
$step = $tiers > 1 ? ($maxK - $minK) / ($tiers - 1) : 0.0;

$tierCounts = array_fill(0, $tiers, 0);
foreach ($byDistAsc as $i => &$t) {
    $tier = (int) floor($i * $tiers / $n);
    if ($tier >= $tiers) {
        $tier = $tiers - 1;
    }
    $t['tier'] = $tier;
    $t['price'] = (int) round(($maxK - $tier * $step) / 100) * 100;
    $tierCounts[$tier]++;
}
unset($t);

/* ── маппинг столов на hall_rows ─────────────────────────────────────────── */

$sectorIdByName = DB::table('sectors')
    ->where('schema_version_id', $svId)
    ->pluck('id', 'name')
    ->all();

$rowsBySector = [];
if ($sectorIdByName !== []) {
    $rowsBySector = DB::table('hall_rows')
        ->whereIn('sector_id', array_values($sectorIdByName))
        ->select('id', 'sector_id', 'number', 'name', 'price_amount')
        ->get()
        ->groupBy('sector_id')
        ->all();
}

$payloadRows = [];
$mapped = 0;
$unmapped = [];
foreach ($byDistAsc as $t) {
    $sid = $sectorIdByName[$t['name']] ?? null;
    if ($sid === null || !isset($rowsBySector[$sid])) {
        $unmapped[] = $t['name'];
        continue;
    }
    foreach ($rowsBySector[$sid] as $row) {
        $payloadRows[] = ['row_id' => (int) $row->id, 'price_amount' => $t['price']];
    }
    $mapped++;
}

/* ── стоячие зоны сеанса ─────────────────────────────────────────────────── */

$zones = [];
if ($session !== null) {
    $zoneIds = DB::table('inventory_items')
        ->where('session_id', $session->id)
        ->whereNotNull('standing_zone_id')
        ->distinct()
        ->pluck('standing_zone_id');
    foreach ($zoneIds as $zid) {
        $zones[] = ['standing_zone_id' => (int) $zid, 'price_amount' => (int) round($floorRub * 100)];
    }
}

/* ── вывод ───────────────────────────────────────────────────────────────── */

$fmt = static fn (int $k): string => number_format($k / 100, 0, '.', ' ') . " ₽";

fwrite(STDOUT, sprintf(
    "Схема #%d (v%s, %s) · столы %d · танцпол %d · точка сцены: %s (%.0f, %.0f)\n",
    $sv->id,
    $sv->version,
    $session ? "сеанс #{$session->id}" : 'предпросмотр',
    count($tables),
    count($floors),
    $ref['how'],
    $ref['x'],
    $ref['y']
));
fwrite(STDOUT, sprintf("Тиры %d: %s … %s\n\n", $tiers, $fmt($maxK), $fmt($minK)));

printf("%-10s %8s %8s %5s %10s\n", 'стол', 'x', 'y', 'тир', 'цена');
$printed = 0;
foreach ($byDistAsc as $t) {
    printf("%-10s %8.0f %8.0f %5d %10s\n", $t['name'], $t['x'], $t['y'], $t['tier'] + 1, $fmt($t['price']));
    if (++$printed === 12 && $n > 20) {
        printf("… ещё %d столов\n", $n - $printed);
        break;
    }
}

fwrite(STDOUT, "\nРаспределение по тирам: " . implode(' / ', $tierCounts) . " (1 = самый дорогой)\n");
fwrite(STDOUT, "Столов отображено на ряды: {$mapped} из " . count($byDistAsc) . "\n");
if ($unmapped !== []) {
    fwrite(STDOUT, "Без рядов в БД: " . implode(', ', $unmapped) . " — инвентарь сеанса ещё не сгенерирован или имена разошлись\n");
}
foreach ($zones as $z) {
    fwrite(STDOUT, "Стоячая зона #{$z['standing_zone_id']} → " . $fmt($z['price_amount']) . "\n");
}

if ($outFile !== null) {
    file_put_contents($outFile, json_encode(['rows' => array_merge($payloadRows, $zones)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fwrite(STDOUT, "\nPayload записан в {$outFile}\n");
}

/* ── применение ──────────────────────────────────────────────────────────── */

if (!$apply) {
    fwrite(STDOUT, "\nПредпросмотр. Для применения: --session <id> --apply [--touch-schema] [--out f.json]\n");
    exit(0);
}
if ($session === null) {
    fwrite(STDERR, "! --apply требует --session <id> (куда применять инвентарь)\n");
    exit(1);
}
if ($payloadRows === [] && $zones === []) {
    fwrite(STDERR, "! нечего применять: нет ни рядов, ни зон\n");
    exit(1);
}

$service = $app->make(InventoryService::class);
$result = $service->updateRowPrices($session->id, array_merge($payloadRows, $zones), $sessionOnly = !$touchSchema);

fwrite(STDOUT, "\nПрименено к сеансу #{$session->id}: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n");
fwrite(STDOUT, $touchSchema
    ? "Режим: сетка зала тоже обновлена — будущие сеансы унаследуют эту цену.\n"
    : "Режим: только этот сеанс — базовая сетка зала не тронута.\n");
