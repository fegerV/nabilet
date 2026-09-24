<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
                        foreach ($payload['sectors'] ?? [] as $sector) {
                $name = (string) ($sector['name'] ?? 'сектор');
                $code = (string) ($sector['code'] ?? '');
                $type = (string) ($sector['type'] ?? 'seated');

                // Уже rows-формат (из импортёра Афиши) — оставляем как есть
                            if (isset($sector['rows']) && is_array($sector['rows'])) {
                                $sectors[] = $sector;
                                continue;
                            }

                            // Канвасный формат: seats[] с row/number/x/y → группируем в ряды
                            if (!isset($sector['seats']) || !is_array($sector['seats'])) {
                                $sectors[] = $sector;
                                continue;
                            }
                            $seats = $sector['seats'];

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
                    // Координаты в канвасе (пиксели) → нормализуем в 0..59/0..39
                    $gridSeats = [];
                    foreach ($rowSeats as $seat) {
                        $gx = (int) round(((int) ($seat['x'] ?? 0)) / $canvasW * 60);
                        $gy = (int) round(((int) ($seat['y'] ?? 0)) / $canvasH * 40);
                        // inventory_items.seat_id — BIGINT: id места обязан быть числом
                                                // (канвасные id «seat-…» — строки → заменяем числом)
                                                $seatCounter += 1;
                                                $gridSeats[] = [
                                                    'id' => $seatCounter,
                                                    'number' => (string) ($seat['number'] ?? (string) count($gridSeats) + 1),
                            'label' => (string) ($seat['label'] ?? "Ряд {$rowNum} Место " . (count($gridSeats) + 1)),
                            'type' => (string) ($seat['kind'] ?? 'standard'),
                            'x' => $gx,
                            'y' => $gy,
                        ];
                    }

                    // Цена ряда: rowPrices перекрывает priceMinor сектора
                                        $rowPrice = 0;
                                        if (isset($sector['rowPrices']) && isset($sector['rowPrices'][(string) $rowNum])) {
                                            $rowPrice = (int) $sector['rowPrices'][(string) $rowNum];
                                        } elseif (isset($sector['priceMinor'])) {
                                            $rowPrice = (int) $sector['priceMinor'];
                                        }

                                        $rows[] = [
                        'number' => (string) $rowNum,
                        'label' => 'Ряд ' . $rowNum,
                        'price_amount' => $rowPrice,
                        'seats' => $gridSeats,
                    ];
                }

                $sectors[] = [
                    'name' => $name,
                    'code' => $code,
                    'type' => $type,
                    'width' => 60,
                    'height' => 40,
                    'x' => 0,
                    'y' => 0,
                    'rows' => $rows,
                ];
            }

            return $sectors;
        }
}
