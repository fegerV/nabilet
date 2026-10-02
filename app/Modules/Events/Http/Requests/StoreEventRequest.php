<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Requests;

use Illuminate\Support\Str;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Events\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    /**
     * Поля, которые принимает API (для совместимости со старыми клиентами), но
     * которых НЕТ в таблице `events` (миграция 000200_002_content). Они
     * валидируются, но вырезаются из payload перед Event::create() — иначе
     * Eloquent ушёл бы в 500 на несуществующую колонку. Даты живут в
     * sessions/event_dates, цены — в inventory_row_prices.
     *
     * @var list<string>
     */
    private const NON_COLUMN_FIELDS = ['start_date', 'end_date', 'timezone', 'currency', 'is_featured', 'min_price', 'max_price', 'metadata'];

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
            // FIX (500 on create): these legacy fields have no column in `events`.
            // They used to be `required` (start_date/timezone/currency) — the form
            // could not save without sending data that was then dropped anyway.
            // Now optional, validated leniently and stripped in stripNonColumnFields().
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:draft,published,archived,cancelled'],
            'is_featured' => ['boolean'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'age_limit' => ['nullable', 'string', 'max:32'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'cover' => ['nullable', 'url', 'max:2048'],
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
            'metadata' => ['nullable'],
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
        $this->preparePayload();
    }

    /**
     * @return array<string, mixed>|mixed
     */
    public function validated($key = null, $default = null)
    {
        // Belt and braces: if a subclass/pipe skipped passedValidation(),
        // still prepare the payload at read time. preparePayload() is idempotent.
        $this->preparePayload();

        return parent::validated($key, $default);
    }

    protected function preparePayload(): void
    {
        $this->storeUploadedPoster();
        $this->normalizePosterMapping();
        $this->stripNonColumnFields();
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

        // Идемпотентность: validated() может вызываться повторно.
        if ($this->input('__poster_stored') === true) {
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

        $this->merge(['poster' => $url, '__poster_stored' => true]);
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

    /**
     * Вырезать не-колоночные ключи из входных данных, чтобы validated()
     * (он перечитывает живой источник данных) вернул только поля таблицы
     * `events`. Повторные вызовы безопасны: удалённые ключи исчезают из
     * all() и не обрабатываются снова.
     */
    protected function stripNonColumnFields(): void
    {
        foreach (self::NON_COLUMN_FIELDS as $field) {
            $this->request->remove($field);
            $this->getInputSource()->remove($field);
        }

        $this->request->remove('__poster_stored');
        $this->getInputSource()->remove('__poster_stored');
    }
}
