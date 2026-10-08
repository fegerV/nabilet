<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Requests;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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
     * ЭТИ ПОЛЯ БОЛЬШЕ НЕ ТЕРЯЮТСЯ МОЛЧА (см. `droppedFields()`). Раньше клиент,
     * отправивший `start_date`, получал 201 и был уверен, что дата сохранена —
     * а её не было нигде. Теперь имена отброшенных полей возвращаются в
     * `meta.ignored_fields`, а факт пишется в лог. Поведение то же (поля
     * по-прежнему не пишутся), но перестало быть невидимым.
     *
     * @var list<string>
     */
    private const NON_COLUMN_FIELDS = ['start_date', 'end_date', 'timezone', 'currency', 'is_featured', 'min_price', 'max_price', 'metadata'];

    /**
     * Что реально было отброшено: поле => значение.
     *
     * Только непустые значения: `null` и `''` в payload — это обычная форма
     * (поле есть в HTML-форме, но не заполнено), а не потеря данных. Сообщать о
     * них значило бы показывать предупреждение на каждом сохранении.
     *
     * @var array<string, mixed>
     */
    private array $droppedFields = [];

    /**
     * Имена полей, значения которых некуда было записать.
     *
     * @return array<string, mixed>
     */
    public function droppedFields(): array
    {
        return $this->droppedFields;
    }

    public function authorize(): bool
    {
        // Write-роуты уже под auth:api + admin (middleware 'admin').
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
            // `bail`/`integer` guard the BIGINT comparison. MySQL coerces a string
            // rather than rejecting it (`WHERE id = '1abc'` matches row 1, warning
            // 1292), so without `bail` the `exists` rule would still run and could
            // report a row for a value `integer` already rejected.
            // See `CartController::addItem()` for the measurements.
            'organization_id' => ['bail', 'nullable', 'integer', 'exists:organizations,id'],
            'category_id' => ['bail', 'nullable', 'integer', 'exists:event_categories,id'],
            // Макет билета. `Rule::exists` с условием по организации, а не просто
            // `exists:ticket_templates,id`: без условия администратор одной
            // организации мог бы назначить своему мероприятию ЧУЖОЙ макет —
            // покупатели увидели бы билет с чужой символикой.
            'ticket_template_id' => [
                'bail',
                'nullable',
                'integer',
                Rule::exists('ticket_templates', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $this->resolveOrganizationId())),
            ],
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
            // `status` здесь СОЗНАТЕЛЬНО отсутствует — как и в UpdateEventRequest.
            //
            // ПОЧЕМУ ИМЕННО ОТСУТСТВИЕ ПРАВИЛА, А НЕ ВЫРЕЗАНИЕ. Правило
            // `in:draft,published,archived,cancelled` пропускало тело
            // `{"status": "published"}`, `EventService::create()` проверял только
            // `EventStatus::isValid()`, и значение уходило в `INSERT` — событие
            // публиковалось в обход `EventPublicationPolicy`, без сеансов и без
            // `published_at`. Это ровно та строка, которую `auditDecision()`
            // помечает `PUBLISHED_WITHOUT_A_MOMENT`, а `EventStatus::publiclyVisible()`
            // пускает в sitemap: «тонкая страница» с первого дня.
            //
            // `Validator::validated()` собирает результат, перебирая `getRules()`,
            // и берёт значения из СНИМКА данных, сделанного при создании
            // валидатора. Поэтому удаление ключа из входного набора (то, что
            // делает `stripStatusField()`) на `validated()` не влияет вообще:
            // ключа не будет в результате только тогда, когда для него нет
            // правила. Так же устроена защита на обновлении, и так же
            // `stripNonColumnFields()` защищает не запись (её защищает
            // `Event::$fillable`), а только мета-отчёт `meta.ignored_fields`.
            //
            // Побочный эффект, и это цель: любое созданное событие — `draft`.
            // Единственный путь к `published` — `POST /events/{id}/publish`,
            // который спрашивает политику. Если однажды понадобится создавать
            // сразу опубликованным, это отдельное решение с явным `published_at`,
            // а не побочный эффект поля в теле запроса.
            //
            // Разница с `UpdateEventRequest` осталась одна и она в пользу
            // мягкости: `status: "banana"` больше не даёт 422, а молча
            // игнорируется — как и все прочие поля без правил.
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
        $this->stripStatusField();
    }

    /**
     * Убирает `status` из входных данных.
     *
     * ЭТО НЕ ТО, ЧТО ЗАЩИЩАЕТ ЗАПИСЬ. Защищает отсутствие правила `status` в
     * `rules()`: `Validator::validated()` перебирает `getRules()` и читает
     * значения из снимка данных, сделанного при создании валидатора, поэтому
     * удаление ключа из входного набора в результат не попадает. Тот, кто
     * вернёт правило и понадеется на этот метод, откроет обход политики снова.
     *
     * Метод нужен для второго: `$request->input('status')` читают мимо
     * `validated()` — логи, отладка, будущий код. Пока значение лежит во входном
     * наборе, оно выглядит как принятое. `UpdateEventRequest` держит такой же
     * метод по той же причине, и симметрия здесь важнее экономии четырёх строк:
     * расхождение этих двух классов и было причиной дефекта.
     */
    protected function stripStatusField(): void
    {
        $this->request->remove('status');
        $this->getInputSource()->remove('status');
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
     *
     * Здесь же фиксируется, что именно было отброшено. Раньше метод молча
     * удалял значения, и клиент, приславший `start_date`, получал 201 без
     * единого признака, что дата не сохранена нигде.
     */
    protected function stripNonColumnFields(): void
    {
        foreach (self::NON_COLUMN_FIELDS as $field) {
            $value = $this->input($field, '__missing__');

            // Пустое значение — это норма: поле присутствует в HTML-форме, но не
            // заполнено. Предупреждать о нём значит шуметь на каждом сохранении.
            if ($value !== '__missing__' && $value !== null && $value !== '' && $value !== []) {
                $this->droppedFields[$field] = $value;
            }

            $this->request->remove($field);
            $this->getInputSource()->remove($field);
        }

        $this->request->remove('__poster_stored');
        $this->getInputSource()->remove('__poster_stored');

        if ($this->droppedFields !== []) {
            Log::warning('Поля мероприятия отброшены: в таблице `events` для них нет колонок.', [
                'fields' => array_keys($this->droppedFields),
                'hint' => 'Дата и время живут в сеансах (sessions.starts_at), цены — в ценах рядов.',
            ]);
        }
    }

    /**
     * Организация, которой должен принадлежать назначаемый шаблон билета.
     *
     * Тот же порядок, что в `EventController::store()`: организация пользователя,
     * иначе первая в системе. Дублирование здесь неизбежно — правило валидации
     * выполняется ДО контроллера, и к моменту проверки `organization_id` в
     * payload ещё нет (его подставляет контроллер).
     */
    private function resolveOrganizationId(): int
    {
        $fromUser = $this->user()?->organizations()->first()?->id;

        if ($fromUser !== null) {
            return (int) $fromUser;
        }

        return (int) (\Nabilet\Modules\Core\Organizations\Models\Organization::query()
            ->orderBy('id')
            ->value('id') ?? 0);
    }
}
