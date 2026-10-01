<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Requests;

use Nabilet\Modules\Events\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
        {
            // Write-роуты уже под auth:sanctum + admin (middleware 'admin').
            // Здесь дублируем роль-проверку, чтобы FormRequest не зависел от
            // несуществующей EventPolicy (can('create') всегда false → 403).
            $user = $this->user();
            if (! $user) {
                return false;
            }

            return $user->roles()->pluck('slug')->intersect(['admin', 'manager'])->isNotEmpty();
        }

    public function rules(): array
    {
        return [
            // `bail`/`integer` guard the BIGINT cast. Without them a non-numeric id
            // reaches `exists` as `where id = 'nope'`, PostgreSQL rejects the cast with
            // SQLSTATE[22P02], and the caller gets a 500 where a 422 is correct.
            // See `CartController::addItem()` for the full explanation.
            'organization_id' => ['bail', 'nullable', 'integer', 'exists:organizations,id'],
            'category_id' => ['bail', 'nullable', 'integer', 'exists:event_categories,id'],
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
            // FIX (posters not saved): the UI and API send `image_url`, but the
            // events table column is `poster`. Accept both here and normalize to
            // `poster` in passedValidation() so the value actually persists.
            'image_url' => ['nullable', 'url', 'max:2048'],
            'poster' => ['nullable', 'url', 'max:2048'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * Normalize the poster field: the form sends `image_url`, the DB column is
     * `poster`. Without this mapping the concert poster was silently dropped.
     *
     * Validator::validated() re-reads data from the LIVE validation data source,
     * so mutating the input here (before the controller calls validated()) is
     * what actually changes the payload that reaches EventService.
     */
    public function passedValidation(): void
    {
        $this->normalizePosterMapping();
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null)
    {
        // Belt and braces: if a subclass/pipe skipped passedValidation(),
        // still map image_url → poster at read time.
        $this->normalizePosterMapping();

        return parent::validated($key, $default);
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
