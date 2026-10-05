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
            'items.*.price' => ['required', 'integer', 'min:0'],
            'promo_code' => ['nullable', 'string', 'max:64'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
