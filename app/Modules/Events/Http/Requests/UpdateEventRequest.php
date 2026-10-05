<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Requests;

use Nabilet\Modules\Events\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
        {
            // Write-роуты уже под auth:api + admin (middleware 'admin').
            // Здесь дублируем роль-проверку, чтобы FormRequest не зависел от
            // несуществующей EventPolicy (can('update') всегда false → 403).
            $user = $this->user();
            if (! $user) {
                return false;
            }

            return $user->roles()->pluck('slug')->intersect(['admin', 'manager'])->isNotEmpty();
        }

    public function rules(): array
    {
        $eventId = $this->route('event')?->id;
        
        return [
            // `bail`/`integer` guard the BIGINT cast — see `CartController::addItem()`.
            'category_id' => ['bail', 'nullable', 'integer', 'exists:event_categories,id'],
            'slug' => ['sometimes', 'string', 'max:255'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:500'],
            // FIX (500 on update): legacy fields without a column in `events` —
            // validated leniently, then stripped before they reach the model.
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'in:draft,published,archived,cancelled'],
            'is_featured' => ['boolean'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'age_limit' => ['nullable', 'string', 'max:32'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'cover' => ['nullable', 'url', 'max:2048'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'poster' => ['nullable', 'url', 'max:2048'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable'],
        ];
    }

    /**
     * FIX (posters not saved): map `image_url` (form field) to `poster`
     * (DB column). See StoreEventRequest::normalizePosterMapping() — same
     * mechanism, shared via trait-like duplication to keep requests explicit.
     */
    public function passedValidation(): void
    {
        $this->normalizePosterMapping();
        $this->stripNonColumnFields();
    }

    /**
     * @return array<string, mixed>|mixed
     */
    public function validated($key = null, $default = null)
    {
        $this->normalizePosterMapping();
        $this->stripNonColumnFields();

        return parent::validated($key, $default);
    }

    /**
     * Поля без колонок в таблице `events` (см. StoreEventRequest) — вырезаем,
     * чтобы Eloquent-update не падал на несуществующем столбце.
     *
     * @var list<string>
     */
    private const NON_COLUMN_FIELDS = ['start_date', 'end_date', 'timezone', 'currency', 'is_featured', 'min_price', 'max_price', 'metadata'];

    protected function stripNonColumnFields(): void
    {
        foreach (self::NON_COLUMN_FIELDS as $field) {
            $this->request->remove($field);
            $this->getInputSource()->remove($field);
        }
    }

    protected function normalizePosterMapping(): void
    {
        $url = $this->input('image_url', '__missing__');

        if ($url === '__missing__') {
            return;
        }

        $this->request->remove('image_url');
        $this->getInputSource()->remove('image_url');

        if ($url !== null && $this->input('poster') === null) {
            $this->merge(['poster' => $url]);
        }
    }
}
