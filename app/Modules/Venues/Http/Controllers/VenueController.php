<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Http\Controllers;

use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\LikePattern;
use Nabilet\Core\Support\Paging;
use Nabilet\Core\Support\PublicationStatus;
use Nabilet\Core\Support\UserOrganization;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Venues\Models\Venue;
use Nabilet\Modules\Venues\Models\VenueTranslation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['organization_id', 'city']);
        // Без клампа `?per_page=1000000` уходил в пагинатор как есть и читал
        // всю таблицу — тот же дефект, что закрыт в остальных списках (`Paging`).
        $perPage = Paging::limit((int) $request->get('per_page', 20), 20, 100);
        
        $query = Venue::query()->with(['halls', 'organization']);
        
        if (isset($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }
        
        if (isset($filters['city'])) {
            // Раньше ввод склеивался в шаблон как есть (`'%' . $city . '%'`):
            // `city=%` возвращал весь каталог площадок, `city=_` — всё, где в
            // названии города есть хотя бы один символ. См. `LikePattern`.
            $query->whereRaw(
                LikePattern::expression('city'),
                [LikePattern::contains((string) $filters['city'])]
            );
        }
        
        // Пагинация читает eager `halls` — скоупнутая модель (см. докблок
        // `Hall`): у гостя публичного каталога контекста нет — исполнение
        // запроса в bypass. Сама выборка площадок фильтруется параметрами
        // каталога; Venue скоупа не имеет.
        $venues = app(OrganizationContext::class)->withoutScope(
            fn () => $query->paginate($perPage)
        );
        
        return response()->json([
            'data' => $venues,
            'meta' => [
                'current_page' => $venues->currentPage(),
                'per_page' => $venues->perPage(),
                'total' => $venues->total(),
                'last_page' => $venues->lastPage(),
            ],
        ]);
    }

    public function show(string $venue): JsonResponse
    {
        // Публичная страница площадки (без auth): shallow binding заменён на
        // lookup по unguessable `public_id` в bypass — гость без контекста,
        // binding на скоупнутой модели (тридцать девятая) ронял бы маршрут ещё
        // до контроллера. Чужой/несуществующий public_id — 404.
        /** @var Venue|null $model */
        $model = app(OrganizationContext::class)->withoutScope(
            fn () => Venue::query()->where('public_id', $venue)->first()
        );

        if ($model === null) {
            throw new NotFoundError('Venue', $venue);
        }

        $venue = $model;

        // Only relations that exist on the model. `translations` and `hallSchemas`
        // were loaded here and neither is defined on `Venue`, so every call threw
        // RelationNotFoundException → 500. Translations live on
        // `venueTranslations`; hall schemas hang off `halls`, not off the venue.
        //
        // Переводы — скоупнутые чтения (двадцать седьмая модель): страница
        // площадки публична (без auth), у гостя контекста нет — в bypass.
        // Адрес решает shallow-binding по route {venue}, читается перевод
        // выбранного места.
        app(OrganizationContext::class)->withoutScope(
            fn () => $venue->load(['halls', 'organization', 'venueTranslations'])
        );

        return response()->json(['data' => $venue]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // `bail` перед `exists`: колонка BIGINT, а MySQL не отвергает
            // сравнение со строкой, а приводит её (`id = '1abc'` совпадает с 1).
            // Без `bail` правило `exists` успевает отработать и пропускает мусор.
            'organization_id' => ['bail', 'nullable', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:venues,slug'],
            'description' => ['nullable', 'string'],
            // Max lengths follow the columns, not a round number: country is
            // VARCHAR(100), region/city VARCHAR(150), address VARCHAR(500). A
            // blanket `max:255` let a 200-char country through validation and then
            // failed in MySQL strict mode as a 500 instead of a 422.
            'country' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['nullable', 'string', PublicationStatus::validationRule()],
        ]);

        $data['status'] ??= PublicationStatus::ACTIVE;

        // Fail closed when there is no organization to attach the venue to. The
        // previous fallback was `Organization::query()->value('id')` — an
        // arbitrary tenant, which would silently file one organization's venue
        // under another's. `organization_id` is NOT NULL, so the alternative to
        // guessing is refusing.
        //
        // Сам выбор организации — `UserOrganization`, а не своя выборка: тот же
        // вопрос задают события, вебхуки и витрина, и ответ должен быть один.
        if (empty($data['organization_id'])) {
            $data['organization_id'] = UserOrganization::id($request->user());
        }

        if (empty($data['organization_id'])) {
            throw new ValidationError([
                'organization_id' => ['No organization to attach this venue to.'],
            ]);
        }

                // БД требует slug NOT NULL — генерим из name, если не передан.
        if (empty($data['slug'])) {
            $base = \Illuminate\Support\Str::slug($data['name']);
            $data['slug'] = $base;
            $i = 2;
            while (Venue::where('slug', $data['slug'])->exists()) {
                $data['slug'] = $base . '-' . $i++;
            }
        }

        $venue = Venue::create($data);
        $venue->load('organization');

        return response()->json(['success' => true, 'data' => $venue], 201);
    }

    public function update(Request $request, Venue $venue): JsonResponse
    {
        $data = $request->validate([
            // `bail` перед `exists`: колонка BIGINT, а MySQL не отвергает
            // сравнение со строкой, а приводит её (`id = '1abc'` совпадает с 1).
            // Без `bail` правило `exists` успевает отработать и пропускает мусор.
            'organization_id' => ['bail', 'nullable', 'integer', 'exists:organizations,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'unique:venues,slug,' . $venue->id],
            'description' => ['nullable', 'string'],
            'country' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['sometimes', 'string', PublicationStatus::validationRule()],
        ]);

        $venue->update(array_filter($data, fn ($v) => $v !== null));
        $venue->load('organization');

        return response()->json(['success' => true, 'data' => $venue]);
    }

    /**
     * DELETE /api/v1/venues/{venue}
     *
     * `sessions.venue_id` — RESTRICT: площадка с сеансами не удаляется ни в
     * каком каскаде (билеты и заказы ссылаются на сеансы). Раньше контроллер
     * делал голый `$venue->delete()`, и MySQL отвечала QueryException → 500
     * «Internal server error» — админ видел только «Не удалось удалить» без
     * причины. 409, а не 422: запрос корректен, отказ вызван состоянием
     * ресурса (та же конвенция, что у `EventController::publish`).
     *
     * Залы при удалении уходят каскадом (`halls.venue_id` CASCADE, включая
     * версии схем) — поэтому в подтверждении на клиенте стоит честный текст.
     */
    public function destroy(Venue $venue): JsonResponse
    {
        $sessionsCount = $venue->sessions()->count();

        if ($sessionsCount > 0) {
            throw new ConflictError(
                'Нельзя удалить площадку: к ней привязаны сеансы. Сначала удалите или переназначьте их.',
                'VENUE_HAS_SESSIONS',
                ['sessions_count' => $sessionsCount],
            );
        }

        $venue->delete();

        return response()->json(['success' => true, 'message' => 'Venue deleted successfully']);
    }

    /**
     * GET /api/v1/venues/{venue}/translations — публичный список переводов
     * площадки (контракт openapi.yaml:2384, тег Translations).
     *
     * То же решение, что в `show()`: витринный путь без auth — у гостя
     * контекста нет, поэтому и адрес площадки (lookup по unguessable
     * `public_id`), и читающий скоуп переводов (двадцать седьмая модель)
     * исполняются в bypass. Чужой/несуществующий public_id — 404 без
     * различий (нельзя перебором узнать, какие площадки существуют).
     *
     * Статус площадки НЕ фильтруется — ровно как в `show()`: у `venues`
     * витринный показ не привязан к `status`, и список переводов не может
     * быть строже страницы самой площадки.
     */
    public function translations(string $venue): JsonResponse
    {
        $context = app(OrganizationContext::class);

        /** @var Venue|null $model */
        $model = $context->withoutScope(
            fn (): ?Venue => Venue::query()->where('public_id', $venue)->first()
        );

        if ($model === null) {
            throw new NotFoundError('Venue', $venue);
        }

        $rows = $context->withoutScope(
            fn () => $model->venueTranslations()->orderBy('locale')->get()
        );

        return response()->json(['data' => $rows]);
    }

    /**
     * PUT /api/v1/venues/{venue}/translations/{locale} — создать или заменить
     * перевод площадки (контракт openapi.yaml:2399): «upsert» на паре
     * `uq_venue_translations (venue_id, locale)`.
     *
     * ВЛАДЕЛЕЦ РЕШАЕТСЯ BINDING'ОМ. Маршрут под `auth:api` + `admin`, а
     * `Venue` — скоупнутая модель (тридцать девятая): binding по `public_id`
     * отфильтровывает чужую площадку ещё до контроллера, и она неотличима от
     * несуществующей (404). `organization_id` строки перевода при создании
     * заполняет трейт `TenantScoped` из родителя (TenantResolver-карта
     * `venue_translations → venue_id → venues`), контекст запроса совпадает
     * с площадкой по построению.
     *
     * $locale нормализуется в нижний регистр: MySQL сравнивает VARCHAR в
     * `*_ci` collation без учёта регистра, поэтому «RU» и «ru» заняли бы
     * одно место в `uq_venue_translations`, оставаясь двумя разными ключами
     * для клиента. Один канонический регистр снимает расщепление.
     *
     * @return JsonResponse 200 — запись есть после вызова (создана или заменена)
     */
    public function updateTranslation(Request $request, Venue $venue, string $locale): JsonResponse
    {
        $locale = $this->normalizeLocale($locale);

        $data = $request->validate([
            // Max lengths follow the columns: name VARCHAR(255) (nullable в
            // схеме — строка без названия не запрещена), address VARCHAR(500),
            // seo_title VARCHAR(500), seo_description VARCHAR(1000),
            // description TEXT.
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'address' => ['nullable', 'string', 'max:500'],
            'seo_title' => ['nullable', 'string', 'max:500'],
            'seo_description' => ['nullable', 'string', 'max:1000'],
        ]);

        // Replace, а не patch: «Create or replace» в контракте означает, что PUT
        // фиксирует ПОЛНЫЙ образ перевода — поле, не присланное в запросе,
        // стирается (nullable-колонка), а не остаётся со старым значением.
        // Иначе два PUT с разными наборами полей давали бы слияние, которое
        // невозможно перезаписать.
        $data = array_merge(['name' => null, 'description' => null, 'address' => null, 'seo_title' => null, 'seo_description' => null], $data);

        $attributes = ['venue_id' => $venue->id, 'locale' => $locale];

        // гонку двух PUT на одну пару (venue, locale) разруливает сам фреймворк:
        // `updateOrCreate` идёт через `firstOrCreate` → `createOrFirst`, который
        // ловит нарушение `uq_venue_translations` (с savepoint'ом) и возвращает
        // строку первого писателя.
        $translation = VenueTranslation::query()->updateOrCreate($attributes, $data);

        return response()->json(['data' => $translation]);
    }

    /**
     * Канонический ключ перевода: нижний регистр, `2-3` буквы языка и до
     * двух уточнений через дефис (`ru`, `en`, `pt-br`, `zh-hans-cn` длина до
     * 10 — граница колонки VARCHAR(10)). Подчёркивание отвергается: `ru_RU`
     * и `ru-RU` — одно и то же, пусть клиент присылает дефис.
     *
     * @return string нормализованный locale
     */
    private function normalizeLocale(string $locale): string
    {
        $normalized = strtolower(trim($locale));

        if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,2}$/', $normalized) !== 1) {
            throw new ValidationError([
                'locale' => ['The locale must be a language tag like "ru", "en" or "pt-br".'],
            ]);
        }

        return $normalized;
    }
}
