<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `bail`/`integer` guard the BIGINT comparison. MySQL coerces a string
            // rather than rejecting it (`WHERE id = '1abc'` matches row 1, warning
            // 1292), so without `bail` the `exists` rule would still run and could
            // report a row for a value `integer` already rejected.
            // See `CartController::addItem()` for the measurements.
            'organization_id' => ['bail', 'required', 'integer', 'exists:organizations,id'],
            'user_id' => ['bail', 'nullable', 'integer', 'exists:users,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id' => ['bail', 'required', 'integer', 'exists:inventory_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
            // `items.*.price` ЗДЕСЬ СОЗНАТЕЛЬНО ОТСУТСТВУЕТ.
            //
            // Цена всегда берётся из `inventory_items.price_amount` — клиент на
            // неё не влияет (`OrderService::createOrder`). Раньше правило
            // `['required','integer','min:0']` стояло, но значение не
            // использовалось: контракт API обещал поле, которого сервер не
            // читает. Это не уязвимость сегодня и мина под завтра.
            //
            // Почему мина. `$request->validated()` возвращает ТОЛЬКО поля, для
            // которых объявлено правило. Пока правило есть, `price` попадает в
            // массив, который уходит в сервис, и достаточно одного рефакторинга
            // (`$order->items()->create($itemData)` вместо поштучного переноса
            // полей), чтобы клиентская цена оказалась рядом с `total_amount` в
            // БД. Убрав правило, мы убираем и поле из `validated()` — то есть
            // закрываем не симптом, а сам канал.
            //
            // Если понадобится сверять цену с клиентом (например, показывать
            // «цена изменилась»), это делается сравнением с серверной ценой и
            // отказом при расхождении, а не доверием присланному числу.
            'promo_code' => ['nullable', 'string', 'max:64'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
