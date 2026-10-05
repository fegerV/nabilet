<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Nabilet\Core\Errors\ValidationError;

/**
 * PATCH /inventory/sessions/{sessionId}/prices
 *
 * Admin-обновление цен по рядам без регенерации геометрии. Тело:
 *
 *   {
 *     "rows": [
 *       { "row_id": 12, "price_amount": 250000 },          // сидячие ряды (hall_rows.id)
 *       { "standing_zone_id": 7, "price_amount": 300000 }  // стоячие зоны
 *     ]
 *   }
 *
 * Допускается и плоский вид {"row_id": ..., "price_amount": ...} — один ряд,
 * а также JSON-карта {"12": 250000, "13": 300000}.
 *
 * Цена (минорные единицы) обязана быть целой >= 0 — это защищает и
 * CHECK ck_inventory_price (price_amount >= 0), и синхронизацию cart_items.
 */
class UpdateRowPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Write-роут под auth:api + admin; дублируем роль-проверку по
        // образцу StoreEventRequest (без Policy — can() был бы всегда false).
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return $user->roles()->pluck('slug')->intersect(['admin', 'manager'])->isNotEmpty();
    }

    /**
     * Нормализуем вход в единый формат `rows` ещё до валидации: админ-панель
     * может прислать как массив объектов, так и одиночный объект/карту.
     */
    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');

        if ($rows === null && $this->has('price_amount')) {
            $rows = [[
                'row_id' => $this->input('row_id'),
                'standing_zone_id' => $this->input('standing_zone_id'),
                'price_amount' => $this->input('price_amount'),
            ]];
        }

        if (is_array($rows)) {
            // JSON-карта { "<row_id>": <price> } тоже принимается.
            if (!array_is_list($rows)) {
                $normalized = [];
                foreach ($rows as $key => $value) {
                    $normalized[] = is_array($value)
                        ? array_merge(['row_id' => $key], $value)
                        : ['row_id' => $key, 'price_amount' => $value];
                }
                $rows = $normalized;
            }
            $this->merge(['rows' => array_values($rows)]);
        }
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.price_amount' => ['required', 'integer', 'min:0'],
            // `bail` перед `exists:` — колонки BIGINT, а MySQL приводит строку к
            // числу вместо отказа (`id = '1abc'` совпадает с 1, warning 1292).
            // Без `bail` правило `exists` отрабатывает после `integer` и
            // пропускает мусор — fail-open. Замеры в `CartController::addItem()`.
            'rows.*.row_id' => ['bail', 'sometimes', 'integer', 'exists:hall_rows,id'],
            'rows.*.standing_zone_id' => ['bail', 'sometimes', 'integer', 'exists:standing_zones,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'Массив rows обязателен: [{"row_id": N, "price_amount": минорные единицы}].',
            'rows.min' => 'Массив rows не может быть пустым.',
            'rows.*.price_amount.required' => 'Для каждого ряда обязателен price_amount (целое >= 0).',
            'rows.*.price_amount.integer' => 'price_amount должен быть целым числом минорных единиц.',
            'rows.*.price_amount.min' => 'price_amount не может быть отрицательным.',
        ];
    }

    /**
     * Каждая запись должна адресовать хотя бы один ряд/зону — иначе UPDATE
     * остался бы без WHERE по ряду и зацепил весь инвентарь сессии.
     *
     * @return array<string, mixed>|mixed
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);

        if ($key !== null) {
            return $data;
        }

        $errors = [];
        foreach ($data['rows'] ?? [] as $i => $row) {
            if (empty($row['row_id']) && empty($row['standing_zone_id'])) {
                $errors["rows.$i.row_id"] = ['Укажите row_id или standing_zone_id для каждой записи.'];
            }
        }

        if ($errors !== []) {
            throw new ValidationError(
                $errors,
                'Каждая запись rows должна содержать row_id или standing_zone_id.'
            );
        }

        return $data;
    }
}
