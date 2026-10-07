<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Nabilet\Modules\Sessions\Models\Session;

/**
 * HallSchemaVersion Model
 */
class HallSchemaVersion extends Model
{
    protected $table = 'hall_schema_versions';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'hall_id',
        'version',
        'revision',
        'status',
        'width',
        'height',
        'background_url',
        'schema_json',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'revision' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'schema_json' => 'array',
            'published_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function hall(): BelongsTo
        {
            return $this->belongsTo(Hall::class);
        }

        /** Конвенция проекта: char(26) public_id NOT NULL → генерим ULID при создании. */
        protected static function booted(): void
        {
            static::creating(function (HallSchemaVersion $version): void {
                if ($version->public_id === null) {
                    $version->public_id = Str::ulid()->toBase32();
                }
            });
        }

            public function sectors(): HasMany
            {
                return $this->hasMany(Sector::class, 'schema_version_id');
            }

    public function sessions(): HasMany
        {
            return $this->hasMany(Session::class, 'schema_version_id');
        }

        /**
         * Конвенция: БД хранит канвасный формат редактора (sectors[].seats[] с x/y),
         * а генератор инвентаря ждёт rows-формат (sectors[].rows[].seats[] c price_amount).
         * Этот метод конвертирует канвас → rows, оставляя уже rows-формат нетронутым.
         */
        public function toInventoryFormat(): array
        {
            $payload = $this->schema_json ?? [];

            // Ширина/высота канваса (если есть) — для нормализации координат
                        $canvas = $payload['canvas'] ?? [];
                        $canvasW = (int) ($canvas['width'] ?? 900);
                        $canvasH = (int) ($canvas['height'] ?? 520);

            $sectors = [];
                        $seatCounter = 0;
                        foreach ($payload['sectors'] ?? [] as $sectorRaw) {
                // B7/C1: «сироты» без мест (сектор только под подпись/рамку)
                // раньше молча выбрасывались из инвентаря. Если у такого
                // сектора есть стоячая зона из staticObjects — превращаем его
                // в standing-сектор, иначе он не попадёт в inventory_items и
                // витрина его не увидит.
                //
                // Столы (`kind: 'table'`) здесь не обрабатываются и не должны:
                // это геометрия-декорация без вместимости и цены, а
                // `ck_inventory_type` допускает только 'seat' и 'standing'.
                // Проверка непустоты схемы (HallService::payloadHasSeats) с
                // этим согласована.
                $sector = is_array($sectorRaw) ? $sectorRaw : [];
                $name = (string) ($sector['name'] ?? 'сектор');
                $code = (string) ($sector['code'] ?? '');
                $type = (string) ($sector['type'] ?? 'seated');

                // Смещение группы сектора на холсте. Редактор хранит координаты
                // мест ЛОКАЛЬНО внутри группы (`Konva.Group({ x: sector.x })`),
                // поэтому нормировать их в 0..59/0..39 без смещения нельзя:
                // сектора, разнесённые по холсту, получали одинаковые
                // нормированные координаты и в витрине (CoordSeatMap, которая
                // строит bbox по всем местам) рисовались друг поверх друга.
                $sectorOffsetX = is_numeric($sector['x'] ?? null) ? (float) $sector['x'] : 0.0;
                $sectorOffsetY = is_numeric($sector['y'] ?? null) ? (float) $sector['y'] : 0.0;

                if (($type === 'seated' || $type === '') && empty($sector['rows']) && empty($sector['seats'])) {
                    $statics = $payload['staticObjects'] ?? [];
                    if (is_array($statics)) {
                        foreach ($statics as $obj) {
                            if (!is_array($obj) || ($obj['kind'] ?? '') !== 'standing') {
                                continue;
                            }
                            $cap = max(0, (int) ($obj['capacity'] ?? 0));
                            if ($cap === 0) {
                                continue;
                            }
                            $type = 'standing';
                            $sector['rows'] = [[
                                'number' => '1',
                                'label' => (string) ($obj['text'] ?? 'Танцпол'),
                                'price_amount' => (int) ($obj['priceMinor'] ?? $obj['price'] ?? $sector['priceMinor'] ?? $sector['price'] ?? 0),
                                'seats' => array_fill(0, $cap, [
                                    'id' => null,
                                    'number' => '',
                                    'label' => '',
                                    'type' => 'standing',
                                    'x' => 0,
                                    'y' => 0,
                                ]),
                            ]];
                            break;
                        }
                    }
                }

                // Уже rows-формат (из импортёра Афиши) — оставляем как есть,
                // но дописываем форму сектора и геометрию стола: без них
                // `hall_tables` заполнить нечем, а витрина не знает, что это
                // банкетный зал (см. InventoryService::generateFromSchema).
                            if (isset($sector['rows']) && is_array($sector['rows'])) {
                                $sectors[] = $this->withShapeAndTable($sector, $sectorOffsetX, $sectorOffsetY);
                                continue;
                            }

                            // Канвасный формат: seats[] с row/number/x/y → группируем в ряды
                            if (!isset($sector['seats']) || !is_array($sector['seats'])) {
                                $sectors[] = $sector;
                                continue;
                            }
                            $seats = $sector['seats'];

                // Канвасный редактор рисует места с kind 'vip' | 'accessible' |
                // 'standard', а ck_seats_type в БД допускает только
                // standard/vip/wheelchair/companion/custom. Без маппинга
                // генерация инвентаря падала на доступных местах (C1).
                $mapSeatType = static fn (string $kind): string => match ($kind) {
                    'wheelchair', 'companion', 'custom', 'vip', 'standard' => $kind,
                    'accessible' => 'wheelchair',
                    default => 'standard',
                };

                $rows = [];
                                $perRow = [];
                                foreach ($seats as $seat) {
                                    $row = isset($seat['row']) ? (int) $seat['row'] : 1;
                    $perRow[$row] ??= [];
                    $perRow[$row][] = $seat;
                }

                $rowKeys = array_keys($perRow);
                                sort($rowKeys);
                                foreach ($rowKeys as $rowNum) {
                                    $rowSeats = $perRow[$rowNum];
                    // Координаты в канвасе (пиксели) → нормализуем в 0..59/0..39.
                    // Локальные координаты места + смещение группы = координаты
                    // холста; только в них сектора сопоставимы между собой.
                    $gridSeats = [];
                    foreach ($rowSeats as $seat) {
                        $gx = (int) round((((float) ($seat['x'] ?? 0)) + $sectorOffsetX) / $canvasW * 60);
                        $gy = (int) round((((float) ($seat['y'] ?? 0)) + $sectorOffsetY) / $canvasH * 40);
                        // inventory_items.seat_id — BIGINT: id места обязан быть числом
                                                // (канвасные id «seat-…» — строки → заменяем числом)
                                                $seatCounter += 1;
                                                $gridSeats[] = [
                                                    'id' => $seatCounter,
                                                    'number' => (string) ($seat['number'] ?? (string) count($gridSeats) + 1),
                            'label' => (string) ($seat['label'] ?? "Ряд {$rowNum} Место " . (count($gridSeats) + 1)),
                            'type' => $mapSeatType((string) ($seat['kind'] ?? 'standard')),
                            'x' => $gx,
                            'y' => $gy,
                            // Индивидуальная цена места (опционально): побеждает
                            // цену ряда при генерации инвентаря (§54).
                            'priceMinor' => isset($seat['priceMinor']) && is_numeric($seat['priceMinor']) && (int) $seat['priceMinor'] > 0
                                ? (int) $seat['priceMinor']
                                : null,
                        ];
                    }

                    // Цена ряда: rowPrices перекрывает priceMinor сектора
                                        $rowPrice = 0;
                                        if (isset($sector['rowPrices']) && isset($sector['rowPrices'][(string) $rowNum])) {
                                            $rowPrice = (int) $sector['rowPrices'][(string) $rowNum];
                                        } elseif (isset($sector['priceMinor'])) {
                                            $rowPrice = (int) $sector['priceMinor'];
                                        } elseif (isset($sector['price'])) {
                                            // Legacy editor/import schemas store the same minor-unit price here.
                                            $rowPrice = (int) $sector['price'];
                                        }

                                        $rows[] = [
                        'number' => (string) $rowNum,
                        'label' => 'Ряд ' . $rowNum,
                        'price_amount' => $rowPrice,
                        'seats' => $gridSeats,
                    ];
                }

                $canvasSector = [
                    'name' => $name,
                    'code' => $code,
                    'type' => $type === 'standing' ? 'standing' : (in_array($type, ['seated', 'mixed'], true) ? $type : 'seated'),
                    'width' => 60,
                    'height' => 40,
                    'x' => 0,
                    'y' => 0,
                    'rows' => $rows,
                ];

                // Форма и геометрия стола считаются по ИСХОДНЫМ координатам
                // холста (места + смещение группы): в $rows они уже нормированы
                // в 0..59/0..39 и о реальном радиусе кольца ничего не говорят.
                $sectors[] = $this->withShapeAndTable(
                    $canvasSector,
                    $sectorOffsetX,
                    $sectorOffsetY,
                    $seats,
                    $this->shapeOf($sector),
                );
            }

            // C1: стоячие зоны из статических объектов редактора. Сектор с
            // type='standing' обрабатывается выше; одиночные standing-зоны без
            // сектора (просто прямоугольник на холсте) раньше терялись —
            // прокидываем их отдельными pseudo-секторами, чтобы InventoryService
            // создал standing_zones + inventory_items с capacity.
            foreach (($payload['staticObjects'] ?? []) as $objRaw) {
                if (!is_array($objRaw)) {
                    continue;
                }
                $kind = (string) ($objRaw['kind'] ?? '');
                $cap = max(0, (int) ($objRaw['capacity'] ?? 0));
                if ($kind !== 'standing' || $cap === 0) {
                    continue;
                }
                // Не дублируем зону, уже «подхваченную» сектором-обёрткой выше.
                $alreadyCovered = false;
                foreach ($sectors as $sec) {
                    if (($sec['type'] ?? '') === 'standing' && ($sec['name'] ?? '') === (string) ($objRaw['text'] ?? '')) {
                        $alreadyCovered = true;
                        break;
                    }
                }
                if ($alreadyCovered) {
                    continue;
                }
                $sectors[] = [
                    'name' => (string) ($objRaw['text'] ?? 'Стоячая зона'),
                    'code' => '',
                    'type' => 'standing',
                    'width' => 60,
                    'height' => 40,
                    'x' => 0,
                    'y' => 0,
                    'rows' => [[
                        'number' => '1',
                        'label' => (string) ($objRaw['text'] ?? 'Танцпол'),
                        'price_amount' => (int) ($objRaw['priceMinor'] ?? $objRaw['price'] ?? 0),
                        'seats' => array_fill(0, $cap, [
                            'id' => null,
                            'number' => '',
                            'label' => '',
                            'type' => 'standing',
                            'x' => 0,
                            'y' => 0,
                        ]),
                    ]],
                ];
            }

        // Код сектора (ck_sectors): редактор не шлёт `code` (в его UI этого
        // поля нет), а уникальный ключ (schema_version_id, code) NOT NULL
        // запрещает пустые значения. Без до-присвоения генерация инвентаря
        // падала 500 при ≥2 секторах (Duplicate entry '<id>-' for
        // uq_sector_schema_code) — зал, собранный в конструкторе, нельзя было
        // пустить в продажу. Заполняем пустые/дублирующиеся коды буквенными
        // индексами A, B, …, AA и гарантируем уникальность в рамках схемы.
        $usedCodes = [];
        $autoIdx = 0;
        foreach ($sectors as &$sec) {
            $c = (string) ($sec['code'] ?? '');
            if ($c === '' || isset($usedCodes[$c])) {
                do {
                    $c = $this->alphaCode($autoIdx);
                    $autoIdx += 1;
                } while (isset($usedCodes[$c]));
            }
            $sec['code'] = $c;
            $usedCodes[$c] = true;
        }
        unset($sec);

        return $sectors;
    }

    /**
     * Форма раскладки мест сектора. Редактор пишет `shape` (grid|arc|table);
     * импортёр Афиши этого поля не знает — считаем такую схему сеткой.
     */
    private function shapeOf(array $sector): string
    {
        $shape = (string) ($sector['shape'] ?? '');

        return in_array($shape, ['grid', 'arc', 'table'], true) ? $shape : 'grid';
    }

    /**
     * Дописать сектору форму и — для банкетного стола — его геометрию.
     *
     * Зачем: `hall_tables` (таблица из ТЗ, раздел «схемы залов») до этого не
     * заполнялась НИКОГДА. Стол в редакторе — это сектор `shape: 'table'` с
     * кольцом мест, и вся его геометрия жила только внутри JSON-схемы. Теперь
     * при генерации инвентаря она материализуется в строку `hall_tables`
     * (см. InventoryService::generateFromSchema), и таблица перестаёт быть
     * мёртвой: её видно в отчётах и можно отрисовать стол на витрине, не
     * разбирая payload.
     *
     * Координаты считаются в ПРОСТРАНСТВЕ ХОЛСТА: места в схеме локальны для
     * группы сектора, поэтому к ним прибавляется смещение `sector.x/sector.y`.
     *
     * @param  array<string, mixed>       $sector     уже собранный сектор
     * @param  list<array<string,mixed>>|null $flatSeats  исходные места в локальных
     *                                                   координатах; если null —
     *                                                   берутся из `$sector['rows']`
     * @return array<string, mixed>
     */
    private function withShapeAndTable(
        array $sector,
        float $offsetX,
        float $offsetY,
        ?array $flatSeats = null,
        ?string $shape = null,
    ): array {
        $shape ??= $this->shapeOf($sector);
        $sector['shape'] = $shape;

        if ($shape !== 'table') {
            return $sector;
        }

        if ($flatSeats === null) {
            $flatSeats = [];
            foreach ($sector['rows'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row['seats'] ?? [] as $seat) {
                    if (is_array($seat)) {
                        $flatSeats[] = $seat;
                    }
                }
            }
        }

        $sector['table'] = $this->tableGeometry($flatSeats, $offsetX, $offsetY);

        return $sector;
    }

    /**
     * Геометрия банкетного стола по кольцу мест (координаты холста).
     *
     * Это тот же расчёт, что делает редактор (`tableLayout` в
     * resources/js/pages/hall-editor/seatGeometry.ts): центр — среднее мест,
     * радиус кольца — максимальное расстояние от центра. Держать формулу в
     * двух местах приходится потому, что редактор считает её на клиенте до
     * сохранения, а серверу нужно материализовать её в БД при генерации
     * инвентаря.
     *
     * @param  list<array<string, mixed>>  $flatSeats
     * @return array{x: float, y: float, width: float, height: float, cx: float, cy: float, ring: float, capacity: int}
     */
    private function tableGeometry(array $flatSeats, float $offsetX, float $offsetY): array
    {
        $count = count($flatSeats);
        if ($count === 0) {
            return [
                'x' => $offsetX, 'y' => $offsetY, 'width' => 0.0, 'height' => 0.0,
                'cx' => $offsetX, 'cy' => $offsetY, 'ring' => 0.0, 'capacity' => 0,
            ];
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $minX = INF;
        $maxX = -INF;
        $minY = INF;
        $maxY = -INF;
        $points = [];

        foreach ($flatSeats as $seat) {
            $x = (float) ($seat['x'] ?? 0) + $offsetX;
            $y = (float) ($seat['y'] ?? 0) + $offsetY;
            $points[] = [$x, $y];
            $sumX += $x;
            $sumY += $y;
            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
        }

        $cx = $sumX / $count;
        $cy = $sumY / $count;

        $ring = 0.0;
        foreach ($points as [$x, $y]) {
            $ring = max($ring, hypot($x - $cx, $y - $cy));
        }

        return [
            'x' => $minX,
            'y' => $minY,
            'width' => $maxX - $minX,
            'height' => $maxY - $minY,
            'cx' => $cx,
            'cy' => $cy,
            'ring' => $ring,
            'capacity' => $count,
        ];
    }

    /**
     * Буквенная «колонка» по индексу: 0→A, 25→Z, 26→AA, 27→AB … (как в Excel).
     * Используется для автогенерации кодов секторов, которых нет в схеме редактора.
     */
    private function alphaCode(int $n): string
    {
        $s = '';
        $n += 1;
        while ($n > 0) {
            $n -= 1;
            $s = chr(ord('A') + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }

        return $s;
    }
}
