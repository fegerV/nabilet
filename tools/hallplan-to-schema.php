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
 *   - Поэтому места, которых нет в `seats[]` ( проданы/заблокированы),
 *     восстанавливаются интерполяцией по анкерам ряда; ряд без единого
 *     анкера раскладывается прямой линией от `rows[].xCoord` медианным
 *     шагом уровня — это аппроксимация, визуально сверяйте overlay.
 *   - Цены приходят в КОПЕЙКАХ на место (`priceInfo.price.value`);
 *     сервисный сбор (`fee`) в схему не переносится.
 *   - Протокол продавца бывает дистанционным (Ticketsteam / «ЯБилеты ARM» —
 *           касса Югра): `gatewaySessionId` — id события у продавца.
 */
$argv = $argv ?? [];

if (PHP_SAPI !== 'cli' || count($argv) < 2) {
    fwrite(STDERR, "usage: php " . basename(__FILE__) . " hallplan.json [--out schema.json]\n");
    exit(1);
}

$in = $argv[1];
$outArgIdx = array_search('--out', $argv, true);
$out = $outArgIdx !== false && isset($argv[$outArgIdx + 1]) ? $argv[$outArgIdx + 1] : 'schema.json';

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

$sectors = [];
$minX = PHP_FLOAT_MAX;
$minY = PHP_FLOAT_MAX;
$maxX = 0.0;
$maxY = 0.0;

foreach ($hallplan['levels'] as $level) {
    $seatsIn = $level['seats'] ?? [];
    $rows = $level['rows'] ?? [];
    $rowPrices = [];

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

    $sectorSeats = [];

    // Полный список рядов уровня — из rows[] (это единственный источник
    // seatCount для проданных/заблокированных мест).
    foreach ($rows as $rowMeta) {
        $rowNo = (string) $rowMeta['row'];
        $count = max(1, (int) $rowMeta['seatCount']);
        $anchors = $anchorsByRow[$rowNo] ?? [];
        ksort($anchors);

        $rowPricesArr = $pricesPerRow[$rowNo] ?? [];
        sort($rowPricesArr);
        $rowPrice = $rowPricesArr === [] ? 0 : $rowPricesArr[intdiv(count($rowPricesArr), 2)];
        $uniform = $rowPricesArr !== [] && count(array_unique($rowPricesArr)) === 1;

        $xs = []; // place → x
        $ys = []; // place → y

        if (count($anchors) >= 2) {
            // Интерполяция по анкерам: ключи плейс-номера не всегда 1..N
            // (нумерация зала может иметь пропуски), поэтому строим
            // кусочно-линейную функцию x(place)/y(place) по опорным точкам.
            $keys = array_keys($anchors);
            foreach ($anchors as $p => $a) {
                $xs[(int) $p] = $a['x'];
                $ys[(int) $p] = $a['y'];
            }
            $minP = min($keys);
            $maxP = max($keys);

            for ($p = 1; $p <= $count; $p++) {
                if (isset($xs[$p])) {
                    continue;
                }
                if ($p < $minP || $p > $maxP) {
                    // Экстраполяция крайним наклоном.
                    if ($minP < $maxP) {
                        $p1 = $p < $minP ? $minP : $maxP;
                        $p2 = $p < $minP ? $minP + 1 : $maxP - 1;
                        $dx = ($anchors[$p2]['x'] - $anchors[$p1]['x']) / ($p2 - $p1);
                        $dy = ($anchors[$p2]['y'] - $anchors[$p1]['y']) / ($p2 - $p1);
                        $base = $p < $minP ? $anchors[$minP] : $anchors[$maxP];
                        $xs[$p] = $base['x'] + $dx * ($p - $p1);
                        $ys[$p] = $base['y'] + $dy * ($p - $p1);
                    } else {
                        $xs[$p] = $anchors[$minP]['x'] + $step * ($p - $minP);
                        $ys[$p] = $anchors[$minP]['y'];
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
                $tL = $anchors[$left];
                $tR = $anchors[$right];
                $t = $right === $left ? 0 : ($p - $left) / ($right - $left);
                $xs[$p] = $tL['x'] + ($tR['x'] - $tL['x']) * $t;
                $ys[$p] = $tL['y'] + ($tR['y'] - $tL['y']) * $t;
            }
        } elseif (count($anchors) === 1) {
            $p0 = (int) array_key_first($anchors);
            $a = $anchors[$p0];
            for ($p = 1; $p <= $count; $p++) {
                $xs[$p] = $a['x'] + $step * ($p - $p0);
                $ys[$p] = $a['y'];
            }
        } else {
            // Ряд без анкеров: прямая от rows[].xCoord (старт первого места).
            $x0 = (float) ($rowMeta['xCoord'] ?? 0);
            $y0 = (float) ($rowMeta['yCoord'] ?? 0);
            for ($p = 1; $p <= $count; $p++) {
                $xs[$p] = $x0 + $step * ($p - 1);
                $ys[$p] = $y0;
            }
        }

        for ($p = 1; $p <= $count; $p++) {
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

            // Перекрытия запрещены валидатором (§50): дуги Яндекса дают
            // вертикаль 12px между соседними рядами — минимально сдвигаем.
            [$x, $y] = $resolveOverlap($xs[$p], $ys[$p]);
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

        if ($uniform && $rowPrice > 0) {
            // цена ряда — канонический способ в наших схемах
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
    'staticObjects' => [],
];

file_put_contents($out, json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$places = array_sum(array_map(static fn (array $s): int => count($s['seats']), $sectors));
echo "sectors: " . count($sectors) . ", мест: {$places}\n";
echo "canvas: {$schema['canvas']['width']}x{$schema['canvas']['height']}\n";
echo "out: {$out}\n";
echo "Дальше: конструктор → «Импорт» → сверить → «Опубликовать» (новой версией).\n";
