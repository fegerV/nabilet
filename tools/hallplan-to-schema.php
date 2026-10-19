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

$hallplan = $payload['result']['hallplan'] ?? ($payload['hallplan'] ?? $payload);
if (!isset($hallplan['levels']) || !is_array($hallplan['levels'])) {
    fwrite(STDERR, "в файле нет hallplan.levels — это точно ответ hallplan?\n");
    exit(1);
}

$SEAT = 16;
/**
 * Масштаб координат Яндекса → холст NABILET.
 *
 * Яндекс рисует места стола с шагом ~14 px по каждой оси (соседние места
 * кольца стоят по диагонали 14×14), а валидатор зала запрещает пары с
 * |dx| < 16 И |dy| < 16 — иначе места визуально слипаются. Коэффициент 1.2
 * — минимальный, при котором после округления шаг становится ≥16; пропорции
 * зала при этом сохраняются полностью (равномерное масштабирование).
 */
$SCALE = 1.2;
$GLOBALS['__seatId'] = 0;
$seatId = static function () use (&$__seatId): string {
    $GLOBALS['__seatId'] += 1;

    return 'seat-' . $GLOBALS['__seatId'];
};
/** Занятые центры (buckèт по 16px): перекрытия валидатор запрещает (§50). */
$occupied = [];
$resolveOverlap = static function (float $x, float $y) use (&$occupied): array {
    $cellX = (int) floor($x / 16);
    $cellY = (int) floor($y / 16);
    while (true) {
        $clear = true;
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                foreach ($occupied[($cellX + $dx) . ':' . ($cellY + $dy)] ?? [] as [$ox, $oy]) {
                    if (abs($x - $ox) < 16 && abs($y - $oy) < 16) {
                        $clear = false;
                        break 2;
                    }
                }
            }
        }
        if ($clear) {
            break;
        }
        // Сдвиг по y на 1px — минимальная правка, дуга сохраняется.
        $y += 1;
        $cellY = (int) floor($y / 16);
    }
    $occupied[$cellX . ':' . $cellY][] = [$x, $y];

    return [$x, $y];
};

/* ── Геометрия столов (режим «каждый row — столик») ─────────────────────
 *
 * Яндекс рисует стол кольцом мест вокруг центра: у стола на 4–5 мест это
 * почти правильная окружность r≈28–29 px, у 9-местного — овал (r 28…46).
 * `seats[]` содержит только ПРОДАВАЕМЫЕ места, поэтому у большинства столов
 * в выдаче 0–1 известное место, а у части — полное кольцо.
 *
 * Первая версия конвертера собирала недостающие места по шаблону «первого
 * стола той же вместимости», привязанному к первому известному месту. Это
 * ломало схему: шаблон чужой ориентации поворачивал кольцо, а место-привязка
 * съезжало с реальной координаты. В итоге зал Вавилона превращался в
 * россыпь точек (см. outputs/babylon-compare.png).
 *
 * Здесь геометрия восстанавливается по самой схеме Яндекса:
 *   1. стол с ≥3 известными местами — окружность по МНК, углы известных мест
 *      сохраняются, пропуски достраиваются равномерно между соседями;
 *   2. стол с 2 местами — окружность радиуса донора через две точки (из двух
 *      решений берём ближайшее к предсказанному по анкеру), поворот кольца —
 *      средняя поправка углов известных мест;
 *   3. стол с 1 местом — центр ставится так, чтобы известное место легло
 *      точно на кольцо донора;
 *   4. стол без мест — центр = `rows[].xCoord/yCoord` + смещение «анкер→центр»
 *      ближайшего донора той же вместимости.
 * Донор — ближайший по анкеру стол той же вместимости с ≥3 известными местами.
 */

/** Окружность по методу наименьших квадратов. null — точки вырождены. */
function hallplanFitCircle(array $points): ?array
{
    $n = count($points);
    if ($n < 3) {
        return null;
    }
    $sx = $sy = $sxx = $syy = $sxy = $sxz = $syz = $sz = 0.0;
    foreach ($points as [$x, $y]) {
        $z = $x * $x + $y * $y;
        $sx += $x;
        $sy += $y;
        $sxx += $x * $x;
        $syy += $y * $y;
        $sxy += $x * $y;
        $sxz += $x * $z;
        $syz += $y * $z;
        $sz += $z;
    }
    $m = [[$sxx, $sxy, $sx], [$sxy, $syy, $sy], [$sx, $sy, (float) $n]];
    $b = [$sxz, $syz, $sz];
    for ($i = 0; $i < 3; $i++) {
        $pivot = $i;
        for ($k = $i + 1; $k < 3; $k++) {
            if (abs($m[$k][$i]) > abs($m[$pivot][$i])) {
                $pivot = $k;
            }
        }
        if (abs($m[$pivot][$i]) < 1e-9) {
            return null;
        }
        [$m[$i], $m[$pivot]] = [$m[$pivot], $m[$i]];
        [$b[$i], $b[$pivot]] = [$b[$pivot], $b[$i]];
        for ($k = $i + 1; $k < 3; $k++) {
            $f = $m[$k][$i] / $m[$i][$i];
            for ($j = $i; $j < 3; $j++) {
                $m[$k][$j] -= $f * $m[$i][$j];
            }
            $b[$k] -= $f * $b[$i];
        }
    }
    $c = [0.0, 0.0, 0.0];
    for ($i = 2; $i >= 0; $i--) {
        $acc = $b[$i];
        for ($j = $i + 1; $j < 3; $j++) {
            $acc -= $m[$i][$j] * $c[$j];
        }
        $c[$i] = $acc / $m[$i][$i];
    }
    $cx = $c[0] / 2;
    $cy = $c[1] / 2;
    $r2 = $c[2] + $cx * $cx + $cy * $cy;

    return $r2 > 0 ? [$cx, $cy, sqrt($r2)] : null;
}

/** Угол в диапазон [0, 2π). */
function hallplanNormAngle(float $a): float
{
    $tau = 2 * M_PI;
    $a = fmod($a, $tau);
    if ($a < 0) {
        $a += $tau;
    }

    return $a;
}

/**
 * Кольцо стола по известным местам: place → [угол, радиус].
 * Пропущенные места распределяются равномерно между соседями по номеру
 * (номер идёт по обходу стола), радиус интерполируется линейно — так
 * сохраняется овальная форма 9-местных столов.
 *
 * @param array<int, array{x: float, y: float}> $known
 * @return array<int, array{a: float, r: float}>
 */
function hallplanRingShape(int $count, array $known, float $cx, float $cy, float $meanR): array
{
    $places = array_keys($known);
    sort($places);
    $angles = [];
    $radii = [];
    foreach ($places as $p) {
        $dx = $known[$p]['x'] - $cx;
        $dy = $known[$p]['y'] - $cy;
        $angles[$p] = atan2($dy, $dx);
        $radii[$p] = hypot($dx, $dy);
    }

    $k = count($places);
    if ($k === $count) {
        $shape = [];
        foreach ($places as $p) {
            $shape[$p] = ['a' => hallplanNormAngle($angles[$p]), 'r' => $radii[$p]];
        }

        return $shape;
    }
    if ($k === 1) {
        $shape = [];
        for ($p = 1; $p <= $count; $p++) {
            $shape[$p] = ['a' => hallplanNormAngle($angles[$places[0]]), 'r' => $meanR];
        }

        return $shape;
    }

    // Направление обхода: сумма кратчайших шагов между соседями по номеру.
    $direction = 0.0;
    for ($i = 0; $i < $k; $i++) {
        $a = $angles[$places[$i]];
        $b = $angles[$places[($i + 1) % $k]];
        $delta = $b - $a;
        while ($delta > M_PI) {
            $delta -= 2 * M_PI;
        }
        while ($delta < -M_PI) {
            $delta += 2 * M_PI;
        }
        $direction += $delta;
    }
    $direction = $direction >= 0 ? 1 : -1;

    $shape = [];
    for ($i = 0; $i < $k; $i++) {
        $p = $places[$i];
        $shape[$p] = ['a' => hallplanNormAngle($angles[$p]), 'r' => $radii[$p]];
        $nextP = $places[($i + 1) % $k];

        // Места между p и nextP по номеру (с переходом через конец круга).
        $between = [];
        $cursor = $p;
        for ($guard = 0; $guard < $count; $guard++) {
            $cursor = $cursor % $count + 1;
            if ($cursor === $nextP) {
                break;
            }
            $between[] = $cursor;
        }
        if ($between === []) {
            continue;
        }

        $aFrom = $angles[$p];
        $aTo = $angles[$nextP];
        $delta = $direction * hallplanNormAngle($direction * ($aTo - $aFrom));
        if ($delta === 0.0) {
            $delta = $direction * 2 * M_PI / $count * (count($between) + 1);
        }
        $step = $delta / (count($between) + 1);
        $rStep = ($radii[$nextP] - $radii[$p]) / (count($between) + 1);
        foreach ($between as $j => $mp) {
            $shape[$mp] = [
                'a' => hallplanNormAngle($aFrom + $step * ($j + 1)),
                'r' => $radii[$p] + $rStep * ($j + 1),
            ];
        }
    }
    ksort($shape);

    return $shape;
}

/**
 * Контур уровня (`levels[].outline`, SVG-путь) → полигоны.
 *
 * Контур — независимый от мест источник истины о форме зала: он есть даже
 * там, где все места распроданы. Нужен как арбитр при достройке рядов:
 * перенос ряда верен, пока не вылезает за границу зала.
 *
 * Поддерживаются M/L/C/Z в абсолютной и относительной записи — только они
 * встречаются в выдаче Яндекса. Кубические кривые разбиваются на 16
 * отрезков: при габарите зала ~900 px ошибка аппроксимации < 0.2 px.
 *
 * @return array<int, array<int, array{0: float, 1: float}>>
 */
function hallplanFlattenPath(string $d): array
{
    preg_match_all('/[MLCZmlcz]|-?\d*\.?\d+(?:[eE]-?\d+)?/', $d, $matches);
    $tokens = $matches[0];
    $rings = [];
    $ring = [];
    $cur = [0.0, 0.0];
    $i = 0;
    $total = count($tokens);
    $num = static function () use (&$i, $tokens): float {
        return isset($tokens[$i]) ? (float) $tokens[$i++] : 0.0;
    };

    while ($i < $total) {
        $cmd = $tokens[$i++];
        if ($cmd === 'M' || $cmd === 'm') {
            if (count($ring) > 2) {
                $rings[] = $ring;
            }
            $x = $num();
            $y = $num();
            $cur = $cmd === 'm' ? [$cur[0] + $x, $cur[1] + $y] : [$x, $y];
            $ring = [$cur];
        } elseif ($cmd === 'L' || $cmd === 'l') {
            $x = $num();
            $y = $num();
            $cur = $cmd === 'l' ? [$cur[0] + $x, $cur[1] + $y] : [$x, $y];
            $ring[] = $cur;
        } elseif ($cmd === 'C' || $cmd === 'c') {
            $p = [];
            for ($k = 0; $k < 6; $k++) {
                $p[] = $num();
            }
            if ($cmd === 'c') {
                $p = [$p[0] + $cur[0], $p[1] + $cur[1], $p[2] + $cur[0], $p[3] + $cur[1], $p[4] + $cur[0], $p[5] + $cur[1]];
            }
            [$x0, $y0] = $cur;
            for ($s = 1; $s <= 16; $s++) {
                $t = $s / 16;
                $u = 1 - $t;
                $ring[] = [
                    $u * $u * $u * $x0 + 3 * $u * $u * $t * $p[0] + 3 * $u * $t * $t * $p[2] + $t * $t * $t * $p[4],
                    $u * $u * $u * $y0 + 3 * $u * $u * $t * $p[1] + 3 * $u * $t * $t * $p[3] + $t * $t * $t * $p[5],
                ];
            }
            $cur = [$p[4], $p[5]];
        } elseif ($cmd === 'Z' || $cmd === 'z') {
            if (count($ring) > 2) {
                $rings[] = $ring;
            }
            $ring = [];
        } else {
            // Число без команды — продолжение предыдущего L.
            $x = (float) $cmd;
            $y = $num();
            $cur = [$x, $y];
            $ring[] = $cur;
        }
    }
    if (count($ring) > 2) {
        $rings[] = $ring;
    }

    return $rings;
}

/**
 * Точка внутри контура (ray casting по всем кольцам пути).
 *
 * @param array<int, array<int, array{0: float, 1: float}>> $rings
 */
function hallplanInsideOutline(float $x, float $y, array $rings): bool
{
    $hit = false;
    foreach ($rings as $ring) {
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i, $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $y) !== ($yj > $y) && $x < (($xj - $xi) * ($y - $yi)) / ($yj - $yi) + $xi) {
                $hit = !$hit;
            }
        }
    }

    return $hit;
}

$sectors = [];
$staticObjects = [];
$pendingStanding = [];
$minX = PHP_FLOAT_MAX;
$minY = PHP_FLOAT_MAX;
$maxX = 0.0;
$maxY = 0.0;

/** Учёт габаритов стоячей зоны на канвасе. */
$extendBounds = static function (float $x, float $y, float $w, float $h) use (&$minX, &$minY, &$maxX, &$maxY): void {
    $minX = min($minX, $x);
    $minY = min($minY, $y);
    $maxX = max($maxX, $x + $w);
    $maxY = max($maxY, $y + $h);
};

foreach ($hallplan['levels'] as $level) {
    $seatsIn = $level['seats'] ?? [];
    $rows = $level['rows'] ?? [];
    $rowPrices = [];

    // ── Стоячая зона (admission: true): «мест» нет, есть вместимость ─────
    // Выражается staticObjects kind 'standing' — InventoryService и
    // валидатор понимают их и создают пристоечный инвентарь (§54, B7/C1).
    //
    // Раскладка зоны откладывается до конца обхода: её место — свободный
    // центр зала, а габариты столов становятся известны только после того,
    // как обработаны все уровни. Раньше зона стояла в жёстко зашитой точке
    // (620, 640) и наезжала на столы (в «Вавилоне» — прямо на дугу столов).
    if (($level['admission'] ?? false) === true || ($rows === [] && $seatsIn !== [])) {
        $zoneCx = 0.0;
        $zoneCy = 0.0;
        foreach ($seatsIn as $item) {
            $zoneCx += (float) ($item['seat']['xCoord'] ?? 0);
            $zoneCy += (float) ($item['seat']['yCoord'] ?? 0);
        }
        if ($seatsIn !== []) {
            $zoneCx /= count($seatsIn);
            $zoneCy /= count($seatsIn);
        }
        foreach (($level['categories'] ?? []) as $ci => $cat) {
            $capacity = (int) ($cat['availableSeatCount'] ?? 0);
            if ($capacity <= 0) {
                continue;
            }
            // Цены в КОПЕЙКАХ лежат в верхнем hallplan.categories (у уровневых
            // priceInfos пуст); берём первую (базовую) цену категории.
            $price = 0;
            foreach ($cat['priceInfos'] ?? [] as $pi) {
                if ((int) ($pi['price']['value'] ?? 0) > 0) {
                    $price = (int) $pi['price']['value'];
                    break;
                }
            }
            if ($price <= 0) {
                foreach (($hallplan['categories'] ?? []) as $topCat) {
                    if ((string) ($topCat['name'] ?? '') === (string) ($cat['name'] ?? '')) {
                        foreach ($topCat['priceInfos'] ?? [] as $pi) {
                            if ((int) ($pi['price']['value'] ?? 0) > 0) {
                                $price = (int) $pi['price']['value'];
                                break 2;
                            }
                        }
                    }
                }
            }
            if ($price <= 0) {
                continue;
            }
            $pendingStanding[] = [
                'id' => 'standing-' . (count($pendingStanding) + 1),
                'text' => trim((string) ($cat['name'] ?? 'Стоячая зона')) . ' · ' . $capacity,
                'capacity' => $capacity,
                'priceMinor' => $price,
                'cx' => $zoneCx * $SCALE,
                'cy' => $zoneCy * $SCALE,
            ];
        }
        continue;
    }

    // Анкеры по рядам: place → [x, y, priceMinor], берём из seats[].
    $anchorsByRow = [];
    $pricesPerRow = [];
    $allStepGaps = [];
    foreach ($seatsIn as $item) {
        $seat = $item['seat'] ?? null;
        if (!is_array($seat)) {
            continue;
        }
        $rowNo = (string) ($seat['row'] ?? '');
        $place = (string) ($seat['place'] ?? '');
        $x = (float) ($seat['xCoord'] ?? 0);
        $y = (float) ($seat['yCoord'] ?? 0);
        $price = (int) round((float) ($item['priceInfo']['price']['value'] ?? 0));
        $anchorsByRow[$rowNo][(int) $place] = ['x' => $x, 'y' => $y];
        $pricesPerRow[$rowNo][] = $price;
        ksort($anchorsByRow[$rowNo]);
    }

    // Медианный шаг уровня — по соседним местам рядов с анкерами.
    foreach ($anchorsByRow as $rowNo => $anchors) {
        $places = array_keys($anchors);
        for ($i = 1, $n = count($places); $i < $n; $i++) {
            $a = $anchors[$places[$i - 1]];
            $b = $anchors[$places[$i]];
            if ((int) $places[$i] - (int) $places[$i - 1] === 1) {
                $d = hypot($b['x'] - $a['x'], $b['y'] - $a['y']);
                if ($d > 4) {
                    $allStepGaps[] = $d;
                }
            }
        }
    }
    sort($allStepGaps);
    $step = $allStepGaps === [] ? 20.0 : $allStepGaps[intdiv(count($allStepGaps), 2)];

    // ── Режим столов (Вавилон): каждый «row» — столик на 4–9 мест ────────
    // Каждый «row» здесь — физический стол, а места лежат кольцом вокруг
    // центра. Полная геометрия известна только для столов, у которых хоть
    // одно место ещё продаётся; остальные достраиваются по донору той же
    // вместимости (см. hallplanRingShape выше).
    $maxRowSize = 0;
    foreach ($rows as $rm) {
        $maxRowSize = max($maxRowSize, (int) ($rm['seatCount'] ?? 0));
    }
    if ($rows !== [] && $maxRowSize > 0 && $maxRowSize <= 12) {
        // 1. Метаданные столов. rows[].xCoord/yCoord — координата ОДНОГО
        //    места, причём номер у каждого стола свой (p1…p5), поэтому анкер
        //    нельзя считать «местом 1».
        $tables = [];
        foreach ($rows as $rowMeta) {
            $rowNo = (string) $rowMeta['row'];
            $known = $anchorsByRow[$rowNo] ?? [];
            ksort($known);
            $tables[$rowNo] = [
                'row' => $rowNo,
                'count' => max(1, (int) ($rowMeta['seatCount'] ?? 0)),
                'anchor' => [(float) ($rowMeta['xCoord'] ?? 0), (float) ($rowMeta['yCoord'] ?? 0)],
                'known' => $known,
                'prices' => $pricesPerRow[$rowNo] ?? [],
            ];
        }

        // 2. Столы с ≥3 известными местами дают и точный центр, и форму кольца.
        foreach ($tables as $rowNo => $t) {
            if (count($t['known']) < 3) {
                continue;
            }
            $points = [];
            foreach ($t['known'] as $a) {
                $points[] = [$a['x'], $a['y']];
            }
            $fit = hallplanFitCircle($points);
            if ($fit === null) {
                continue;
            }
            [$cx, $cy, $radius] = $fit;
            $tables[$rowNo]['center'] = [$cx, $cy];
            $tables[$rowNo]['meanR'] = $radius;
            $tables[$rowNo]['shape'] = hallplanRingShape($t['count'], $t['known'], $cx, $cy, $radius);
            // Смещение «анкер ряда → центр стола»: по нему ставим столы, от
            // которых не осталось ни одного продаваемого места.
            $tables[$rowNo]['anchorOffset'] = [$cx - $t['anchor'][0], $cy - $t['anchor'][1]];
        }

        $donorsByCount = [];
        foreach ($tables as $rowNo => $t) {
            if (isset($t['shape'])) {
                $donorsByCount[$t['count']][] = $rowNo;
            }
        }
        $donorFor = static function (array $tables, array $donorsByCount, array $table): int|string|null {
            $best = null;
            $bestDistance = INF;
            foreach ($donorsByCount[$table['count']] ?? [] as $candidateRow) {
                $candidate = $tables[$candidateRow];
                $d = hypot(
                    $candidate['anchor'][0] - $table['anchor'][0],
                    $candidate['anchor'][1] - $table['anchor'][1],
                );
                if ($d < $bestDistance) {
                    $bestDistance = $d;
                    $best = $candidateRow;
                }
            }

            return $best;
        };

        // 3. Достройка столов с 0–2 известными местами.
        foreach ($tables as $rowNo => $t) {
            if (isset($t['shape'])) {
                continue;
            }
            $count = $t['count'];
            $known = $t['known'];
            $donorRow = $donorFor($tables, $donorsByCount, $t);
            if ($donorRow === null) {
                // Полных столов этой вместимости нет (схема Яндекса такого не
                // даёт). Раскладываем ровное кольцо вокруг анкера.
                $cx = $t['anchor'][0];
                $cy = $t['anchor'][1];
                $shape = [];
                for ($p = 1; $p <= $count; $p++) {
                    $shape[$p] = ['a' => -M_PI / 2 + 2 * M_PI * ($p - 1) / $count, 'r' => 28.0];
                }
                $tables[$rowNo]['center'] = [$cx, $cy];
                $tables[$rowNo]['shape'] = $shape;
                continue;
            }

            $donor = $tables[$donorRow];
            $donorShape = $donor['shape'];
            $donorOffset = $donor['anchorOffset'];

            if (count($known) === 0) {
                // Стол распродан целиком: центр = анкер + смещение донора.
                $cx = $t['anchor'][0] + $donorOffset[0];
                $cy = $t['anchor'][1] + $donorOffset[1];
                $shape = $donorShape;
            } elseif (count($known) === 1) {
                // Одно место: ставим центр так, чтобы оно легло точно на
                // кольцо донора — координата известного места не двигается.
                $place = array_key_first($known);
                $anchorSeat = $known[$place];
                $ring = $donorShape[$place] ?? ['a' => 0.0, 'r' => $donor['meanR']];
                $cx = $anchorSeat['x'] - $ring['r'] * cos($ring['a']);
                $cy = $anchorSeat['y'] - $ring['r'] * sin($ring['a']);
                $shape = $donorShape;
            } else {
                // Два места: окружность радиуса донора через две точки; из
                // двух центров берём ближайший к предсказанному по анкеру.
                // Кольцо доворачиваем средней поправкой углов известных мест.
                $places = array_keys($known);
                $first = $known[$places[0]];
                $second = $known[$places[1]];
                $radius = $donor['meanR'];
                $half = hypot($second['x'] - $first['x'], $second['y'] - $first['y']) / 2;
                if ($half > $radius) {
                    $radius = $half;
                }
                $h = sqrt(max(0.0, $radius * $radius - $half * $half));
                $midX = ($first['x'] + $second['x']) / 2;
                $midY = ($first['y'] + $second['y']) / 2;
                $ux = $half > 1e-9 ? ($second['x'] - $first['x']) / (2 * $half) : 1.0;
                $uy = $half > 1e-9 ? ($second['y'] - $first['y']) / (2 * $half) : 0.0;
                $predX = $t['anchor'][0] + $donorOffset[0];
                $predY = $t['anchor'][1] + $donorOffset[1];
                $candidateA = [$midX - $uy * $h, $midY + $ux * $h];
                $candidateB = [$midX + $uy * $h, $midY - $ux * $h];
                $useA = hypot($candidateA[0] - $predX, $candidateA[1] - $predY)
                    <= hypot($candidateB[0] - $predX, $candidateB[1] - $predY);
                $cx = $useA ? $candidateA[0] : $candidateB[0];
                $cy = $useA ? $candidateA[1] : $candidateB[1];

                $sinSum = 0.0;
                $cosSum = 0.0;
                $samples = 0;
                foreach ($known as $p => $a) {
                    if (!isset($donorShape[$p])) {
                        continue;
                    }
                    $delta = atan2($a['y'] - $cy, $a['x'] - $cx) - $donorShape[$p]['a'];
                    $sinSum += sin($delta);
                    $cosSum += cos($delta);
                    $samples++;
                }
                $rotation = $samples > 0 ? atan2($sinSum, $cosSum) : 0.0;
                $shape = [];
                foreach ($donorShape as $p => $ring) {
                    $shape[$p] = ['a' => hallplanNormAngle($ring['a'] + $rotation), 'r' => $ring['r']];
                }
            }

            $tables[$rowNo]['center'] = [$cx, $cy];
            $tables[$rowNo]['shape'] = $shape;
        }

        // 4. Сектора-столы. Известные места сохраняют координаты Яндекса
        //    байт-в-байт; достроенные ложатся на кольцо.
        //
        // Цена целиком распроданного стола в выдаче Яндекса отсутствует (мест
        // не осталось). Берём цену БЛИЖАЙШЕГО стола с известной ценой: тарифы
        // в зале идут зонами, поэтому сосед почти всегда в том же тарифе.
        // Первая цена уровня здесь врёт — у «Вавилона» она ставила 5000 ₽
        // половине зала, где реальные тарифы 3000–4500 ₽.
        $pricedTables = [];
        foreach ($tables as $t) {
            $knownPrices = array_values(array_filter($t['prices'], static fn (int $v): bool => $v > 0));
            if ($knownPrices === []) {
                continue;
            }
            sort($knownPrices);
            $pricedTables[] = [
                'center' => $t['center'] ?? $t['anchor'],
                'price' => $knownPrices[intdiv(count($knownPrices), 2)],
            ];
        }
        $nearestPrice = static function (array $center) use ($pricedTables): int {
            $best = 0;
            $bestDistance = INF;
            foreach ($pricedTables as $candidate) {
                $d = hypot($candidate['center'][0] - $center[0], $candidate['center'][1] - $center[1]);
                if ($d < $bestDistance) {
                    $bestDistance = $d;
                    $best = $candidate['price'];
                }
            }

            return $best;
        };

        foreach ($tables as $rowNo => $t) {
            $count = $t['count'];
            $known = $t['known'];
            $center = $t['center'] ?? $t['anchor'];
            $shape = $t['shape'] ?? [];
            $rowPricesArr = $t['prices'];
            sort($rowPricesArr);
            $rowPrice = $rowPricesArr === [] ? 0 : $rowPricesArr[intdiv(count($rowPricesArr), 2)];
            $uniform = $rowPricesArr !== [] && count(array_unique($rowPricesArr)) === 1;

            $tableSeats = [];
            for ($p = 1; $p <= $count; $p++) {
                if (isset($known[$p])) {
                    $px = $known[$p]['x'];
                    $py = $known[$p]['y'];
                } elseif (isset($shape[$p])) {
                    $px = $center[0] + $shape[$p]['r'] * cos($shape[$p]['a']);
                    $py = $center[1] + $shape[$p]['r'] * sin($shape[$p]['a']);
                } else {
                    continue;
                }
                $tableSeats[] = ['place' => $p, 'x' => $px, 'y' => $py];
            }

            // Цена стола: у анкеров точная; целиком проданный — у соседа.
            $pricesFromAnchors = array_values(array_filter($rowPricesArr, fn (int $v) => $v > 0));
            $tablePrice = $pricesFromAnchors === []
                ? $nearestPrice($center)
                : $pricesFromAnchors[intdiv(count($pricesFromAnchors), 2)];

            $sectorSeats = [];
            foreach ($tableSeats as $ts) {
                $x = (int) round($ts['x'] * $SCALE - $SEAT / 2);
                $y = (int) round($ts['y'] * $SCALE - $SEAT / 2);
                $seatPayload = [
                    'id' => $seatId(),
                    'row' => 1,
                    'number' => $ts['place'],
                    'x' => $x,
                    'y' => $y,
                ];
                if ($rowPrice > 0 && !$uniform) {
                    $seatPayload['priceMinor'] = $rowPrice;
                }
                $sectorSeats[] = $seatPayload;
                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x + $SEAT);
                $maxY = max($maxY, $y + $SEAT);
            }

            // Цена сектора — стола: единая для столика.
            $sectorPrice = $tablePrice > 0
                ? $tablePrice
                : ($uniform ? $rowPrice : 0);
            if ($sectorPrice === 0 && ($level['prices'][0]['value'] ?? 0) > 0) {
                $sectorPrice = (int) $level['prices'][0]['value'];
            }

            $sectors[] = [
                'id' => 'level-' . ($level['id'] ?? 'x') . '-row-' . $rowNo,
                'name' => 'Стол ' . $rowNo,
                'x' => 0,
                'y' => 0,
                'priceMinor' => $sectorPrice,
                'type' => 'seated',
                'shape' => 'table',
                'seats' => $sectorSeats,
            ];
        }

        continue;
    }

    $sectorSeats = [];

    // Контур уровня (`levels[].outline`) — арбитр для достроенных рядов:
    // перенос ряда верен, пока места не вылезают за границу зала.
    $outlineRings = isset($level['outline']) && is_string($level['outline'])
        ? hallplanFlattenPath($level['outline'])
        : [];
    $outsideCount = static function (array $points) use ($outlineRings): int {
        if ($outlineRings === []) {
            return 0;
        }
        $n = 0;
        foreach ($points as $point) {
            if (!hallplanInsideOutline($point[0], $point[1], $outlineRings)) {
                $n++;
            }
        }

        return $n;
    };

    $rowMetaByNo = [];
    foreach ($rows as $rowMeta) {
        $rowMetaByNo[(string) $rowMeta['row']] = $rowMeta;
    }

    /* ── Фаза 1. Ряды с анкерами: интерполяция по известным местам ──────
     * Ключи плейс-номеров не всегда 1..N (нумерация зала может иметь
     * пропуски), поэтому строим кусочно-линейную функцию x(place)/y(place)
     * по опорным точкам. Координаты анкеров сохраняются байт-в-байт.
     */
    $rowPoints = []; // rowNo => [place => [x, y]]
    foreach ($rows as $rowMeta) {
        $rowNo = (string) $rowMeta['row'];
        $count = max(1, (int) $rowMeta['seatCount']);
        $anchors = $anchorsByRow[$rowNo] ?? [];
        ksort($anchors);
        if (count($anchors) < 2) {
            continue;
        }

        $points = [];
        foreach ($anchors as $p => $a) {
            $points[(int) $p] = [$a['x'], $a['y']];
        }
        $keys = array_keys($anchors);
        $minP = min($keys);
        $maxP = max($keys);

        for ($p = 1; $p <= $count; $p++) {
            if (isset($points[$p])) {
                continue;
            }
            if ($p < $minP || $p > $maxP) {
                // Экстраполяция крайним наклоном. Вторую опорную точку берём
                // по СПИСКУ ключей, а не minP+1: нумерация мест бывает с
                // пропусками (у «Ледового» в ряду 1 место 6 продано —
                // minP+1 не существует).
                $p1 = $p < $minP ? $minP : $maxP;
                $p2 = $p < $minP ? ($keys[1] ?? $minP) : ($keys[count($keys) - 2] ?? $maxP);
                if ($p2 !== $p1) {
                    $dx = ($anchors[$p2]['x'] - $anchors[$p1]['x']) / ($p2 - $p1);
                    $dy = ($anchors[$p2]['y'] - $anchors[$p1]['y']) / ($p2 - $p1);
                    $base = $anchors[$p1];
                    $points[$p] = [$base['x'] + $dx * ($p - $p1), $base['y'] + $dy * ($p - $p1)];
                } else {
                    $points[$p] = [$anchors[$p1]['x'] + $step * ($p - $p1), $anchors[$p1]['y']];
                }
                continue;
            }
            // Между анкерами — линейно.
            $left = $minP;
            $right = $maxP;
            foreach ($keys as $k) {
                if ($k <= $p) {
                    $left = max($left, $k);
                }
                if ($k >= $p && $k < $right) {
                    $right = $k;
                }
            }
            $t = $right === $left ? 0 : ($p - $left) / ($right - $left);
            $points[$p] = [
                $anchors[$left]['x'] + ($anchors[$right]['x'] - $anchors[$left]['x']) * $t,
                $anchors[$left]['y'] + ($anchors[$right]['y'] - $anchors[$left]['y']) * $t,
            ];
        }
        ksort($points);
        $rowPoints[$rowNo] = $points;
    }

    /* ── Фаза 2. Ряды без анкеров: перенос геометрии ближайшего ряда ────
     * Яндекс отдаёт координаты только продаваемых мест. Ряд, где всё
     * распродано, известен лишь парой (seatCount, первое место), и его
     * форма — самая большая неопределённость импорта.
     *
     * Раньше такой ряд рисовался горизонталью от первого места. В
     * зале-«веере» (СурГУ: партер — трапеция с диагональными боковинами)
     * горизонталь уезжала от соседей на 40–80 px и вылезала за контур.
     *
     * Теперь ряд копируется с БЛИЖАЙШЕГО ряда, у которого есть координаты,
     * и переносится так, чтобы его первое место легло на известную точку
     * этого ряда. Вектор переноса — разность первых мест: он же и есть
     * радиальное смещение между рядами, поэтому шаг между рядами
     * сохраняется автоматически (важно: валидатор требует ≥16 px, а Яндекс
     * рисует 12–22 px).
     */
    // Донорами служат ТОЛЬКО ряды с анкерами: иначе достроенный ряд стал бы
    // донором для следующего и ошибка копилась бы цепочкой.
    $anchorRowPoints = $rowPoints;

    foreach ($rows as $rowMeta) {
        $rowNo = (string) $rowMeta['row'];
        $count = max(1, (int) $rowMeta['seatCount']);
        if (isset($rowPoints[$rowNo])) {
            continue;
        }
        $anchors = $anchorsByRow[$rowNo] ?? [];
        ksort($anchors);
        $p0 = count($anchors) === 1 ? (int) array_key_first($anchors) : 1;
        $start = $anchors[$p0] ?? [
            'x' => (float) ($rowMeta['xCoord'] ?? 0),
            'y' => (float) ($rowMeta['yCoord'] ?? 0),
        ];

        // Донор — ближайший по первому месту ряд, у которого есть геометрия.
        $donorNo = null;
        $donorDistance = INF;
        foreach ($anchorRowPoints as $candidateNo => $candidatePoints) {
            $candidateMeta = $rowMetaByNo[(string) $candidateNo] ?? null;
            if ($candidateMeta === null) {
                continue;
            }
            $d = hypot(
                (float) ($candidateMeta['xCoord'] ?? 0) - $start['x'],
                (float) ($candidateMeta['yCoord'] ?? 0) - $start['y'],
            );
            if ($d < $donorDistance) {
                $donorDistance = $d;
                $donorNo = $candidateNo;
            }
        }

        // Прямая от первого места — запасной вариант, когда донора нет или
        // перенос вылезает за контур, а прямая нет.
        $line = [];
        for ($p = 1; $p <= $count; $p++) {
            $line[$p] = [$start['x'] + $step * ($p - $p0), $start['y']];
        }

        $points = $line;
        if ($donorNo !== null) {
            $donor = $rowPoints[$donorNo];
            $donorKeys = array_keys($donor);
            $donorStart = $donor[$p0] ?? $donor[$donorKeys[0]];
            $shiftX = $start['x'] - $donorStart[0];
            $shiftY = $start['y'] - $donorStart[1];
            $lastP = $donorKeys[count($donorKeys) - 1];
            $prevP = $donorKeys[count($donorKeys) - 2] ?? $lastP;
            $tailDx = $lastP === $prevP ? $step : ($donor[$lastP][0] - $donor[$prevP][0]) / ($lastP - $prevP);
            $tailDy = $lastP === $prevP ? 0.0 : ($donor[$lastP][1] - $donor[$prevP][1]) / ($lastP - $prevP);

            $shifted = [];
            for ($p = 1; $p <= $count; $p++) {
                if (isset($donor[$p])) {
                    $shifted[$p] = [$donor[$p][0] + $shiftX, $donor[$p][1] + $shiftY];
                } else {
                    // Дальше последнего места донора — его крайним шагом.
                    $shifted[$p] = [
                        $donor[$lastP][0] + $shiftX + $tailDx * ($p - $lastP),
                        $donor[$lastP][1] + $shiftY + $tailDy * ($p - $lastP),
                    ];
                }
            }
            if ($outsideCount($shifted) <= $outsideCount($line)) {
                $points = $shifted;
            }
        }
        ksort($points);
        $rowPoints[$rowNo] = $points;
    }

    /* ── Фаза 3. Выпуск мест ───────────────────────────────────────────
     * Анкеры — истина Яндекса: их координаты не двигаются вообще. Поэтому
     * сначала ВСЕ анкерные места уровня попадают в $occupied, и только
     * потом достроенные ищут себе свободное место. Раньше наоборот:
     * достроенный ряд наезжал на соседний и $resolveOverlap утягивал за
     * собой реальные места (в «Ледовом» — до 70 px).
     */
    foreach ($rows as $rowMeta) {
        $rowNo = (string) $rowMeta['row'];
        foreach ($anchorsByRow[$rowNo] ?? [] as $anchor) {
            $occupied[(int) floor($anchor['x'] / 16) . ':' . (int) floor($anchor['y'] / 16)][] = [$anchor['x'], $anchor['y']];
        }
    }

    $emitOrder = [];
    foreach ($rows as $rowMeta) {
        $emitOrder[] = (string) $rowMeta['row'];
    }
    usort($emitOrder, static function (string $a, string $b) use ($anchorsByRow): int {
        $na = count($anchorsByRow[$a] ?? []);
        $nb = count($anchorsByRow[$b] ?? []);

        return $nb <=> $na;
    });

    foreach ($emitOrder as $rowNo) {
        $rowMeta = $rowMetaByNo[$rowNo];
        $count = max(1, (int) $rowMeta['seatCount']);
        $anchors = $anchorsByRow[$rowNo] ?? [];
        ksort($anchors);
        $points = $rowPoints[$rowNo] ?? [];

        $rowPricesArr = $pricesPerRow[$rowNo] ?? [];
        sort($rowPricesArr);
        $rowPrice = $rowPricesArr === [] ? 0 : $rowPricesArr[intdiv(count($rowPricesArr), 2)];
        $uniform = $rowPricesArr !== [] && count(array_unique($rowPricesArr)) === 1;

        for ($p = 1; $p <= $count; $p++) {
            if (!isset($points[$p])) {
                continue;
            }
            $placeName = (string) $p;
            // Цена: точная у доступного места, у проданного — ряда (билеты
            // одного ряда тарифицируются единообразно с малым числом
            // исключений), иначе медианная по уровню.
            $price = 0;
            if (isset($anchors[$p])) {
                foreach ($seatsIn as $item) {
                    if ((string) ($item['seat']['row'] ?? '') === $rowNo
                        && (string) ($item['seat']['place'] ?? '') === $placeName) {
                        $price = (int) round((float) ($item['priceInfo']['price']['value'] ?? 0));
                        break;
                    }
                }
            } elseif ($uniform) {
                $price = $rowPrice;
            }

            // Перекрытия запрещены валидатором (§50). Анкер уже занял своё
            // место в $occupied — его координаты не трогаем; достроенное
            // место ищет свободную точку сдвигом по y на 1 px.
            if (isset($anchors[$p])) {
                [$x, $y] = $points[$p];
            } else {
                [$x, $y] = $resolveOverlap($points[$p][0], $points[$p][1]);
            }
            $x = (int) round($x - $SEAT / 2);
            $y = (int) round($y - $SEAT / 2);
            $sectorSeats[] = [
                'id' => $seatId(),
                'row' => (int) $rowNo,
                'number' => $p,
                'x' => $x,
                'y' => $y,
            ] + ($price > 0 && !($uniform && $rowPrice > 0) ? ['priceMinor' => $price] : []);

            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x + $SEAT);
            $maxY = max($maxY, $y + $SEAT);
        }

        if ($rowPrice > 0) {
            // Единый ряд — каноническая цена ряда; смешанный (Ледовый Дворец
            // держит два тарифа внутри одного ряда) — медиана как fallback
            // валидатора: точные цены мест уже проставлены per-seat.
            $rowPrices[$rowNo] = $rowPrice;
        }
    }
    if ($sectorSeats === []) {
        continue;
    }

    // Ценомерная лестница: ряды, проданные целиком (анкеров нет), получают
    // цену ближайшего известного ряда того же уровня — тарифы в залe идут
    // «фронт дороже» и не скачут между соседними рядами.
    if (isset($rowPrices) && $rowPrices !== []) {
        foreach ($rows as $rowMeta) {
            $rowNo = (string) $rowMeta['row'];
            if (isset($rowPrices[$rowNo])) {
                continue;
            }
            $best = null;
            $bestDist = PHP_INT_MAX;
            foreach ($rowPrices as $knownRow => $knownPrice) {
                $d = abs((int) $knownRow - (int) $rowNo);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $best = $knownPrice;
                }
            }
            if ($best !== null) {
                $rowPrices[$rowNo] = $best;
            }
        }
        ksort($rowPrices);
    }

    // Секторная цена — требование валидатора (§50): положительное целое.
    // Медиана цен рядов уровня; конкретные ряды переопределяют её (rowPrices).
    $levelPrices = [];
    foreach ($rowPrices as $p) {
        if (is_int($p) && $p > 0) {
            $levelPrices[] = $p;
        }
    }
    sort($levelPrices);
    $sectorPrice = $levelPrices === [] ? 0 : $levelPrices[intdiv(count($levelPrices), 2)];

    $sectorId = 'level-' . ($level['id'] ?? count($sectors) + 1);
    $sectors[] = [
        'id' => $sectorId,
        'name' => (string) ($level['name'] ?? ('Сектор ' . (count($sectors) + 1))),
        'x' => 0,
        'y' => 0,
        'priceMinor' => $sectorPrice,
        'type' => 'seated',
        'shape' => 'grid',
        'seats' => $sectorSeats,
        'rowPrices' => $rowPrices ?? [],
    ];
}

if ($sectors === []) {
    fwrite(STDERR, "ни один уровень не дал мест\n");
    exit(1);
}

// ── Стоячие зоны: свободный центр зала ────────────────────────────────
// Яндекс ставит admission-зону одной точкой в центре танцпола; раскладываем
// категории вокруг неё. Ширину делим пропорционально √вместимости — площадь
// зоны растёт как число людей, поэтому линейная пропорция делала бы зону на
// 500 человек непомерно длинной. Габарит — доля от габарита столов, чтобы
// зона не вылезала за пределы зала и не наезжала на столы.
if ($pendingStanding !== []) {
    $tablesW = $maxX - $minX;
    $tablesH = $maxY - $minY;
    $zoneW = max(240.0, min(900.0, $tablesW * 0.5));
    $zoneH = max(120.0, min(340.0, $tablesH * 0.25));
    $weights = [];
    $weightSum = 0.0;
    foreach ($pendingStanding as $i => $zone) {
        $weights[$i] = sqrt((float) max(1, $zone['capacity']));
        $weightSum += $weights[$i];
    }
    $gap = 20.0;
    $usableW = $zoneW - $gap * (count($pendingStanding) - 1);
    $cursorX = $pendingStanding[0]['cx'] - $zoneW / 2;
    $zoneY = $pendingStanding[0]['cy'] - $zoneH / 2;
    foreach ($pendingStanding as $i => $zone) {
        $w = $usableW * ($weights[$i] / $weightSum);
        $staticObjects[] = [
            'kind' => 'standing',
            'id' => $zone['id'],
            'text' => $zone['text'],
            'x' => (int) round($cursorX),
            'y' => (int) round($zoneY),
            'width' => (int) round($w),
            'height' => (int) round($zoneH),
            'capacity' => $zone['capacity'],
            'priceMinor' => $zone['priceMinor'],
        ];
        $extendBounds($cursorX, $zoneY, $w, $zoneH);
        $cursorX += $w + $gap;
    }
}

// ── Сцена (--stage) ───────────────────────────────────────────────────
// Положение сцены Яндекс не отдаёт. В залах с выраженным «передом» её
// добавляют в конструкторе руками (в «СурГУ» так и было: статичный объект
// «Сцена» под партером). Флаг переносит этот шаг в конвертер: прямоугольник
// по центру габарита, ниже последнего ряда.
if ($withStage) {
    $stageW = max(160.0, min(420.0, ($maxX - $minX) * 0.45));
    $stageH = 70.0;
    $stageX = ($minX + $maxX) / 2 - $stageW / 2;
    $stageY = $maxY + 30;
    $staticObjects[] = [
        'id' => 'static-stage',
        'kind' => 'stage',
        'text' => 'Сцена',
        'x' => (int) round($stageX),
        'y' => (int) round($stageY),
        'width' => (int) round($stageW),
        'height' => (int) round($stageH),
        'locked' => false,
        'opacity' => 1,
        'rotation' => 0,
    ];
    $extendBounds($stageX, $stageY, $stageW, $stageH);
}

// Сдвиг всей раскладки в положительный квадрант канваса.
if ($minX < 40 || $minY < 40) {
    $dx = $minX < 40 ? 60 - (int) floor($minX) : 0;
    $dy = $minY < 40 ? 60 - (int) floor($minY) : 0;
    foreach ($sectors as &$sector) {
        foreach ($sector['seats'] as &$seat) {
            $seat['x'] += $dx;
            $seat['y'] += $dy;
        }
        unset($seat);
    }
    unset($sector);
    foreach ($staticObjects as &$object) {
        $object['x'] += $dx;
        $object['y'] += $dy;
    }
    unset($object);
    $maxX += $dx;
    $maxY += $dy;
}

$schema = [
    'version' => 1,
    'canvas' => [
        'width' => (int) ceil($maxX + 60),
        'height' => (int) ceil($maxY + 60),
    ],
    'background' => null,
    'backgrounds' => [],
    'sectors' => $sectors,
    'staticObjects' => $staticObjects,
];

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
