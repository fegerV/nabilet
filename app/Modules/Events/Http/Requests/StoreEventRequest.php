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
            // FIX (poster upload): external URL was the only way to set a poster
            // (image_url → poster mapping). Now multipart uploads are accepted too:
            // `poster_file` is stored on the public disk and its URL written into
            // `poster`. Mutually exclusive with the URL fields — see mergePosterFile().
            'poster_file' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'poster_file.image' => 'Файл афиши должен быть изображением.',
            'poster_file.mimes' => 'Файл афиши должен быть в формате jpg, png или webp.',
            'poster_file.max' => 'Файл афиши не должен превышать 5 МБ.',
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
        $this->storeUploadedPoster();
        $this->normalizePosterMapping();
    }

    /**
     * @return array<string, mixed>|mixed
     */
    public function validated($key = null, $default = null)
    {
        // Belt and braces: if a subclass/pipe skipped passedValidation(),
        // still map image_url → poster at read time.
        $this->storeUploadedPoster();
        $this->normalizePosterMapping();

        return parent::validated($key, $default);
    }

    /**
     * Сохранить загруженный файл афиши на public-диск и подставить URL в `poster`.
     *
     * Файл имеет приоритет над текстовыми полями: UI шлёт FormData, где
     * poster_file и (иногда) пустой image_url сосуществуют. Ошибка записи на
     * диск превращается в 422 (ValidationError), а не в 500 — это ожидаемый
     * инфраструктурный отказ (права на storage, не смонтирован volume).
     */
    protected function storeUploadedPoster(): void
    {
        if (! $this->hasFile('poster_file')) {
            return;
        }

        $file = $this->file('poster_file');

        $this->request->remove('image_url');
        $this->getInputSource()->remove('image_url');

        try {
            // hashName() даёт имя с расширением, выведенным из реального мим-типа
            // (проверен валидатором `image`/`mimes`) — исходное расширение файла
            // клиенту не верим. storeAs — детерминированный путь posters/<ULID>.<ext>.
            $ext = pathinfo((string) $file->hashName(), PATHINFO_EXTENSION) ?: 'jpg';
            $stored = $file->storeAs('posters', Str::ulid()->toBase32() . '.' . $ext, [
                'disk' => 'public',
                'visibility' => 'public',
            ]);
        } catch (\Throwable $e) {
            throw new ValidationError(
                ['poster_file' => ['Не удалось сохранить файл афиши: ' . $e->getMessage()]],
                'Ошибка загрузки афиши.'
            );
        }

        $url = rtrim((string) config('filesystems.disks.public.url'), '/') . '/' . ltrim((string) $stored, '/');

        $this->merge(['poster' => $url]);
        // Из входных данных файл убираем — в Event::create/update попадает только `poster`.
        $this->request->remove('poster_file');
        $this->getInputSource()->remove('poster_file');
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
