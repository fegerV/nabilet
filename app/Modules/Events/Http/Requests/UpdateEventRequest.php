<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Requests;

use Nabilet\Modules\Events\Http\Requests\Concerns\HandlesPosterUpload;
use Nabilet\Modules\Events\Models\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
{
    // Сохранение файла афиши + перенос legacy `image_url` → `poster`. Раньше
    // этих методов здесь не было вовсе, и новая афиша при редактировании молча
    // не сохранялась (жалоба «афиша не сохраняется»). Общий трейт с Store.
    use HandlesPosterUpload;

    /**
     * Что реально было отброшено: поле => значение. См. StoreEventRequest —
     * механизм общий, здесь он нужен по той же причине: молчаливая потеря
     * значений неотличима от успешного сохранения.
     *
     * @var array<string, mixed>
     */
    private array $droppedFields = [];

    /**
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
            // Макет билета. Скоуп по организации МЕРОПРИЯТИЯ, а не по организации
            // пользователя: при правке чужого (в рамках мультитенантности)
            // мероприятия шаблон обязан принадлежать той же организации, что и
            // само мероприятие, иначе связь перестанет быть согласованной.
            'ticket_template_id' => [
                'bail',
                'nullable',
                'integer',
                Rule::exists('ticket_templates', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $this->eventOrganizationId())),
            ],
            // Уникальность ГЛОБАЛЬНАЯ и с исключением самого события
            // (SEO-аудит P-03). Без `ignore()` сохранение карточки без правки
            // адреса падало бы на собственном значении: slug — это адрес, а
            // форма админки отправляет его всегда, даже когда его не меняли.
            //
            // Смена адреса при этом законна и сопровождается редиректом 301 со
            // старого URL (`EventController::rememberSlugChange()`), поэтому
            // правило `sometimes`, а не `required`: обновление одного описания
            // не обязано присылать адрес.
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('events', 'slug')->ignore($eventId),
            ],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:500'],
            // FIX (500 on update): legacy fields without a column in `events` —
            // validated leniently, then stripped before they reach the model.
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            // `status` здесь СОЗНАТЕЛЬНО отсутствует.
            //
            // Раньше правило `in:draft,published,archived,cancelled` позволяло
            // перевести событие в `published`, отправив его в теле обычного
            // `PUT /api/v1/events/{id}`. Ни `EventPublicationPolicy`, ни
            // `canPublish()` при этом не вызывались, поэтому опубликовать
            // событие БЕЗ СЕАНСОВ — без даты, без цены и без кнопки «купить» —
            // не мешало ничего. Такая страница уходит в sitemap
            // (`EventStatus::publiclyVisible()` включает `published`), и это
            // «тонкая страница», за которую Google понижает весь раздел.
            //
            // Единственный путь к смене статуса — `POST /events/{id}/publish`
            // и `POST /events/{id}/cancel`, которые вызывают политику.
            //
            // Если поле всё-таки придёт в теле, оно молча отбрасывается, а не
            // даёт 422: форма админки отправляет `status` вместе с остальными
            // полями, и жёсткая ошибка сломала бы сохранение карточки. Отсюда
            // же `unset` в `passedValidation()` ниже.
            'is_featured' => ['boolean'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'age_limit' => ['nullable', 'string', 'max:32'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'cover' => ['nullable', 'url', 'max:2048'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'poster' => ['nullable', 'url', 'max:2048'],
            // FIX (афиша не сохраняется при редактировании): правила для файла
            // афиши здесь не было, поэтому multipart `poster_file` не валидировался
            // и не сохранялся — новая афиша молча терялась. Правило то же, что в
            // StoreEventRequest; сохранение делает трейт HandlesPosterUpload.
            'poster_file' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            // Управление индексацией страницы (аудит P-23). См.
            // `StoreEventRequest` — правило то же, и по той же причине: поле
            // уходит в `<meta name="robots">` дословно, поэтому список
            // директив закрыт, а не свободен.
            //
            // `index` здесь не отменит автоматический `noindex` для
            // scheduled/cancelled: запрет по статусу применяется при рендере
            // страницы (`EventPageController::effectiveRobots()`), а не в
            // валидации, и поле его только дополняет.
            'robots' => [
                'nullable',
                'string',
                'max:255',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! \Nabilet\Modules\Events\Domain\EventRobots::isValid($value)) {
                        $fail(
                            'Поле «robots» может содержать только директивы: '
                            . implode(', ', \Nabilet\Modules\Events\Domain\EventRobots::ALLOWED) . '.'
                        );
                    }
                },
            ],
            'metadata' => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'Такой адрес мероприятия уже занят. Укажите другой — '
                . 'он входит в публичную ссылку на страницу.',
            'poster_file.image' => 'Файл афиши должен быть изображением.',
            'poster_file.mimes' => 'Файл афиши должен быть в формате jpg, png или webp.',
            'poster_file.max' => 'Файл афиши не должен превышать 5 МБ.',
        ];
    }

    public function passedValidation(): void
    {
        $this->storeUploadedPoster();
        $this->normalizePosterMapping();
        $this->stripNonColumnFields();
        $this->stripStatusField();
    }

    /**
     * @return array<string, mixed>|mixed
     */
    public function validated($key = null, $default = null)
    {
        $this->storeUploadedPoster();
        $this->normalizePosterMapping();
        $this->stripNonColumnFields();
        $this->stripStatusField();

        return parent::validated($key, $default);
    }

    /**
     * Убирает `status` из входных данных.
     *
     * ОТДЕЛЬНО ОТ `stripNonColumnFields()`, потому что `status` — НАСТОЯЩАЯ
     * колонка `events`: её нельзя внести в список «полей без столбца», не
     * вводя в заблуждение следующего читателя. Вырезается по другой причине —
     * это смена состояния, а не правка атрибута, и у неё есть собственный
     * эндпоинт с проверкой политики.
     *
     * Молча, без 422: форма админки отправляет `status` в общем payload, и
     * жёсткая ошибка сломала бы обычное сохранение карточки. Значение просто
     * не доходит до модели.
     */
    protected function stripStatusField(): void
    {
        $this->request->remove('status');
        $this->getInputSource()->remove('status');
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
            $value = $this->input($field, '__missing__');

            // См. StoreEventRequest::stripNonColumnFields() — пустое значение
            // это норма, потеря данных это непустое.
            if ($value !== '__missing__' && $value !== null && $value !== '' && $value !== []) {
                $this->droppedFields[$field] = $value;
            }

            $this->request->remove($field);
            $this->getInputSource()->remove($field);
        }

        if ($this->droppedFields !== []) {
            Log::warning('Поля мероприятия отброшены при обновлении: колонок нет в `events`.', [
                'event_id' => $this->route('event')?->id,
                'fields' => array_keys($this->droppedFields),
            ]);
        }
    }

    /**
     * Организация редактируемого мероприятия.
     *
     * Берётся у самого мероприятия: правило проверяет, что назначаемый шаблон
     * принадлежит той же организации, что и событие.
     */
    private function eventOrganizationId(): int
    {
        $event = $this->route('event');

        if ($event instanceof Event && $event->organization_id !== null) {
            return (int) $event->organization_id;
        }

        return (int) ($this->user()?->organizations()->first()?->id ?? 0);
    }

}
