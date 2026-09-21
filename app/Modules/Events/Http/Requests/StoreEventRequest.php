<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Requests;

use Nabilet\Modules\Events\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Event::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'exists:organizations,id'],
            'category_id' => ['nullable', 'exists:event_categories,id'],
            'public_id' => ['nullable', 'string', 'max:64', 'unique:events,public_id'],
            'slug' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'timezone' => ['required', 'timezone'],
            'status' => ['required', 'in:draft,published,archived,cancelled'],
            'is_featured' => ['boolean'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', 'size:3'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
