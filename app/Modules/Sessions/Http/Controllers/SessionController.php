<?php

declare(strict_types=1);

namespace Nabilet\Modules\Sessions\Http\Controllers;

use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\Paging;
use Nabilet\Modules\HallSchemas\Domain\SchemaVersionState;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Sessions\StateMachines\SessionStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Inventory\Services\InventoryService;
use Nabilet\Modules\Venues\Models\Hall;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SessionController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['event_id', 'hall_id', 'status']);
        // Без клампа `?per_page=1000000` уходил в пагинатор как есть и читал
        // всю таблицу — тот же дефект, что закрыт в остальных списках (`Paging`).
        $perPage = Paging::limit((int) $request->get('per_page', 20), 20, 100);
        
        $query = Session::query()->with(['event', 'hall', 'venue', 'schemaVersion']);

        // Гость витрины видит только сеансы опубликованных мероприятий,
        // Administrator — все. Раньше публичный список отдавал черновики
        // вместе с сырыми внутренними связями.
        $isStaff = \Nabilet\Modules\Core\Users\Support\StaffRole::isStaff($request->user());
        if ($isStaff) {
            // Админ видит сеансы ТОЛЬКО своей организации. Раньше ветка staff
            // вообще не фильтровала, а исполнялась в bypass: admin A получал
            // список сеансов admin'а B вместе с venue/schemaVersion (I-4).
            $query->where('organization_id', app(OrganizationContext::class)->id());
        } else {
            // Подзапрос по ТАБЛИЦЕ, а не whereHas по Eloquent-модели Event:
            // модель tenant-scoped, и её global scope сработал бы внутри
            // безконтекстного запроса витрины (TenantContextMissingError).
            $query->whereIn('event_id', static function ($q): void {
                $q->select('id')
                    ->from('events')
                    ->whereIn('status', \Nabilet\Modules\Events\Domain\EventStatus::publiclyVisible());
            });
        }
        
        if (isset($filters['event_id'])) {
            $query->where('event_id', $filters['event_id']);
        }
        
        if (isset($filters['hall_id'])) {
            $query->where('hall_id', $filters['hall_id']);
        }
        
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        // Публичный каталог сеансов (без auth): и сами сеансы (тридцать
        // шестая модель), и связи `hall`/`schemaVersion` — скоупнутые модели,
        // у гостя контекста нет — исполнение запроса в bypass. Выборка
        // фильтруется параметрами каталога.
        $sessions = app(OrganizationContext::class)->withoutScope(
            fn () => $query->paginate($perPage)
        );

        return response()->json([
            'data' => $sessions,
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
                'last_page' => $sessions->lastPage(),
            ],
        ]);
    }

    public function show(int $session): JsonResponse
    {
        // Публичная карточка сеанса (без auth): implicit binding заменён на
        // lookup в bypass — у гостя контекста нет, binding на скоупнутой
        // модели давал бы отказ на маршруте. Найдена чужая строка — 404.
        /** @var Session|null $model */
        $model = app(OrganizationContext::class)->withoutScope(
            fn (): ?Session => Session::query()->find($session)
        );

        if ($model === null) {
            throw new NotFoundError('Session', (string) $session);
        }
        if (\Nabilet\Modules\Core\Users\Support\StaffRole::isStaff(request()->user())) {
            // Админ чужой организации не должен получать карточку сеанса
            // (вместе с инвентарём и схемой) только по числовому id.
            app(OrganizationContext::class)->assertCanAccess($model->organization_id, 'Session', $session);
        } else {
            $eventStatus = app(OrganizationContext::class)->withoutScope(
                fn () => $model->event?->status
            );
            if ($eventStatus === null || ! \Nabilet\Modules\Events\Domain\EventStatus::isPubliclyVisible((string) $eventStatus)) {
                throw new NotFoundError('Session', (string) $session);
            }
        }

        $session = $model;

        // `venue` в списке связей, а не только `hall`: администратор выбирает
        // площадку, и в списке сеансов должно быть видно, ЧЬИ это залы. Иначе
        // «Большой зал» у двух площадок неотличим.
        //
        // Карту сеанса показывает и витрина (маршрут без auth): гость работает
        // без тенант-контекста, а `inventoryItems` — скоупнутая модель, поэтому
        // вся load-цепочка — в bypass.
        app(OrganizationContext::class)->withoutScope(fn () => $session->load(
            ['event', 'hall', 'venue', 'schemaVersion', 'inventoryItems']
        ));

        return response()->json(['data' => $session]);
    }

    public function store(Request $request): JsonResponse
    {
        // `bail` перед каждым `exists:` — не стилистика. Колонки BIGINT, а MySQL
        // не отвергает сравнение со строкой, а приводит её: `WHERE id = '1abc'`
        // совпадает с id = 1 (warning 1292). Без `bail` правило `exists` успевает
        // отработать после `integer` и пропускает мусор (fail-open).
        // Замеры — в `CartController::addItem()`.
        $data = $request->validate([
            'event_id' => ['bail', 'required', 'integer', 'exists:events,id'],
            'venue_id' => ['bail', 'nullable', 'integer', 'exists:venues,id'],
            'hall_id' => ['bail', 'required', 'integer', 'exists:halls,id'],
            'schema_version_id' => ['bail', 'nullable', 'integer', 'exists:hall_schema_versions,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'sales_start_at' => ['nullable', 'date'],
            'sales_end_at' => ['nullable', 'date', 'after:sales_start_at'],
            'timezone' => ['nullable', 'string', 'max:64'],
            // Словарь — из машины состояний, а не своей строкой. Здесь стоял
            // список `scheduled,on_sale,held,completed,cancelled`, разошедшийся
            // с `SessionStateMachine` в обе стороны: `held` в машине и в
            // `ck_sessions_status` нет вовсе (это состояние позиции инвентаря,
            // `InventoryItemStateMachine::HELD`), зато отсутствовали `draft`,
            // `sold_out` и `closed` — обязательные состояния продаж. Подробнее
            // в `SessionStateMachine::all()`.
            'status' => ['nullable', 'string', Rule::in(SessionStateMachine::all())],
        ]);

        $data['status'] ??= SessionStateMachine::SCHEDULED;
        $data['timezone'] ??= config('app.timezone', 'UTC');

        // БД требует venue_id NOT NULL; форма его не шлёт — берём площадку зала.
        if (empty($data['venue_id']) && !empty($data['hall_id'])) {
            $data['venue_id'] = Hall::query()
                ->where('id', $data['hall_id'])
                ->value('venue_id');
        }

        // БД требует schema_version_id NOT NULL — берём последнюю published схему зала.
        // Статус — из `SchemaVersionState`, а не литералом: `Hall::currentSchemaVersion()`
        // ищет то же самое, и расхождение дало бы сеанс без схемы зала.
        if (empty($data['schema_version_id']) && !empty($data['hall_id'])) {
            $data['schema_version_id'] = HallSchemaVersion::query()
                ->where('hall_id', $data['hall_id'])
                ->where('status', SchemaVersionState::PUBLISHED)
                ->orderByDesc('version')
                ->value('id');
        }

        // Обе проверки — ПОСЛЕ вывода значений: площадка выводится из зала, и
        // только после этого пару можно сверить.
        $this->assertHallBelongsToVenue($data);
        $this->assertHallHasPublishedSchema($data);
        // Явный schema_version_id проверяется отдельно: без этого PATCH/POST
        // позволяли привязать ЧЕРНОВИК схемы или версию ЧУЖОГО зала —
        // инвентарь строился из неожиданной геометрии.
        $this->assertExplicitSchemaVersion($data);

        // Сеанс и инвентарь — ОДНА транзакция. Раньше строка сеанса
        // коммитилась до генерации мест, и при любой ошибке генерации
        // (FK, конфликт геометрии, нехватка памяти) оставался сеанс без
        // инвентаря: витрина показывала его, но купить в нём было нечего.
        // Повтор запроса создавал ещё один такой же «пустой» сеанс.
        $session = DB::transaction(function () use ($data): Session {
            /** @var Session $session */
            $session = Session::create($data);

            // Создаём места (геометрия + инвентарь) из схемы зала.
            // Канвасный формат редактора конвертируется в rows автоматически
            // (HallSchemaVersion::toInventoryFormat).
            if ($session->schema_version_id !== null) {
                $schemaVersion = HallSchemaVersion::query()
                    ->whereKey($session->schema_version_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->inventoryService->generateFromSchema($schemaVersion, $session);
            }

            return $session->load(['event', 'hall', 'schemaVersion']);
        });

        return response()->json(['success' => true, 'data' => $session], 201);
    }

    public function update(Request $request, Session $session): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['bail', 'sometimes', 'integer', 'exists:events,id'],
            'venue_id' => ['bail', 'nullable', 'integer', 'exists:venues,id'],
            'hall_id' => ['bail', 'sometimes', 'integer', 'exists:halls,id'],
            'schema_version_id' => ['bail', 'nullable', 'integer', 'exists:hall_schema_versions,id'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'sales_start_at' => ['nullable', 'date'],
            'sales_end_at' => ['nullable', 'date', 'after:sales_start_at'],
            'timezone' => ['nullable', 'string', 'max:64'],
            // Тот же словарь из машины, что и при создании: две копии строки
            // разошлись бы при первой же правке.
            'status' => ['sometimes', 'string', Rule::in(SessionStateMachine::all())],
        ]);

        // Вся операция — в одной транзакции с локом строки сеанса. Порядок
        // критичен: проверка «можно ли пересобрать», UPDATE сеанса и генерация
        // инвентаря обязаны видеть ОДНО и то же состояние. Раньше проверка и
        // UPDATE коммитились до генерации; если генерация падала (например,
        // на уставшей геометрии), сеанс оставался с НОВОЙ схемой и СТАРЫМ
        // инвентарём. Без лока строки два параллельных PATCH могло бы оба
        // решить, что схема меняется с одной и той же версии.
        $session = DB::transaction(function () use ($session, &$data): Session {
            // БД требует venue_id NOT NULL; при смене зала обновляем площадку зала.
            if (empty($data['venue_id']) && !empty($data['hall_id'])) {
                $data['venue_id'] = Hall::query()
                    ->where('id', $data['hall_id'])
                    ->value('venue_id');
            }

            // Явно присланный null не обязан означать «обнулить» (колонка NOT NULL,
            // иначе SQL-ошибка 500): PATCH без смены зала трактует null как «не
            // менять». При смене зала null заполнится последней published-схемой ниже.
            if (array_key_exists('schema_version_id', $data) && $data['schema_version_id'] === null && empty($data['hall_id'])) {
                unset($data['schema_version_id']);
            }

            // БД требует schema_version_id NOT NULL — берём последнюю published схему зала.
            // Тот же `SchemaVersionState`, что и при создании.
            if (empty($data['schema_version_id']) && !empty($data['hall_id'])) {
                $data['schema_version_id'] = HallSchemaVersion::query()
                    ->where('hall_id', $data['hall_id'])
                    ->where('status', SchemaVersionState::PUBLISHED)
                    ->orderByDesc('version')
                    ->value('id');
            }

            /** @var Session $locked */
            $locked = Session::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            // Сверка пары нужна и здесь: PATCH может прислать ТОЛЬКО `venue_id`
            // (зал остаётся прежним) или ТОЛЬКО `hall_id` (площадка выводится
            // из зала), поэтому берём ИТОГОВЫЕ значения, а не тело запроса.
            $this->assertHallBelongsToVenue([
                'venue_id' => $data['venue_id'] ?? $locked->venue_id,
                'hall_id' => $data['hall_id'] ?? $locked->hall_id,
            ]);
            $this->assertHallHasPublishedSchema($data);
            $this->assertExplicitSchemaVersion($data, $locked);

            // Итоговая версия схемы после PATCH: присланная или прежняя. Смена
            // версии — единственный триггер пересборки инвентаря: правка даты
            // или статуса не должна трогать места.
            $effectiveSchemaId = (int) ($data['schema_version_id'] ?? $locked->schema_version_id);
            $schemaChanged = $effectiveSchemaId !== (int) $locked->schema_version_id;

            // Проверка ДО update(), но УЖЕ под локом: бронирование блокирует
            // те же строки инвентаря, поэтому гонка «пересборка vs бронь»
            // сериализуется, а при 409 транзакция откатывает ВСЁ.
            if ($schemaChanged) {
                $this->assertInventoryRegenerable($locked);
            }

            $locked->update($data);

            if ($schemaChanged) {
                $schemaVersion = HallSchemaVersion::query()
                    ->whereKey($effectiveSchemaId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->inventoryService->generateFromSchema($schemaVersion, $locked);
            }

            return $locked->load(['event', 'hall', 'schemaVersion']);
        });

        return response()->json(['success' => true, 'data' => $session]);
    }

    /**
     * Зал обязан принадлежать выбранной площадке.
     *
     * `venue_id` и `hall_id` валидируются по отдельности (`exists:venues,id` и
     * `exists:halls,id`), поэтому `{"venue_id": 1, "hall_id": 2}`, где зал 2
     * стоит на площадке 3, проверку проходил: обе ссылки по отдельности
     * существуют, а пары не существует. Сеанс сохранялся бы, и витрина
     * показывала бы концерт по адресу одной площадки в зале другой — это заметил
     * бы покупатель, а не администратор.
     *
     * Вызывается ПОСЛЕ вывода площадки из зала: если площадку не прислали, она
     * берётся из зала, и тогда пара согласована по построению.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertHallBelongsToVenue(array $data): void
    {
        $hallId = $data['hall_id'] ?? null;
        $venueId = $data['venue_id'] ?? null;

        if (empty($hallId) || empty($venueId)) {
            return;
        }

        $hallVenueId = Hall::query()->whereKey($hallId)->value('venue_id');

        if ($hallVenueId !== null && (int) $hallVenueId !== (int) $venueId) {
            throw new ValidationError(
                ['hall_id' => ['Выбранный зал принадлежит другой площадке. Выберите зал этой площадки.']],
                'Зал не относится к выбранной площадке.'
            );
        }
    }

    /**
     * У зала должна быть опубликованная схема.
     *
     * `sessions.schema_version_id` — NOT NULL, а схема нужна, чтобы создать
     * места: `InventoryService::generateFromSchema()` работает именно по версии
     * схемы. Если у зала нет опубликованной версии, вывод оставлял поле пустым,
     * INSERT падал на NOT NULL, и `POST /sessions` отвечал 500 «Something went
     * wrong» на полностью корректный запрос. Администратор видел внутреннюю
     * ошибку вместо «опубликуйте схему зала».
     *
     * @param  array<string, mixed>  $data
     */
    private function assertHallHasPublishedSchema(array $data): void
    {
        if (empty($data['hall_id']) || ! empty($data['schema_version_id'])) {
            return;
        }

        throw new ValidationError(
            ['hall_id' => ['У зала нет опубликованной схемы — места создавать не из чего. Опубликуйте схему зала и повторите.']],
            'Зал без опубликованной схемы.'
        );
    }

    /**
     * Явно присланный `schema_version_id` обязан быть published-версией ЭТОГО зала.
     *
     * `exists:hall_schema_versions,id` проверял только существование строки:
     * без этой проверки POST/PATCH привязывали к сеансу ЧЕРНОВИК схемы (все
     * `status`, включая draft, проходят exists) или версию ЧУЖОГО зала —
     * инвентарь строился из неожиданной геометрии, а покупатель видел чужую
     * рассадку. Правило то же, что выводит схему автоматически ниже: только
     * published и только своего зала.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertExplicitSchemaVersion(array $data, ?Session $session = null): void
    {
        $schemaId = $data['schema_version_id'] ?? null;

        if (empty($schemaId)) {
            return;
        }

        $hallId = $data['hall_id'] ?? $session?->hall_id;
        $schema = HallSchemaVersion::query()->find($schemaId);

        if ($schema === null) {
            throw new ValidationError(
                ['schema_version_id' => ['Версия схемы не найдена.']],
                'Схема не найдена.'
            );
        }

        if ($hallId !== null && (int) $schema->hall_id !== (int) $hallId) {
            throw new ValidationError(
                ['schema_version_id' => ['Выбранная версия схемы принадлежит другому залу. Выберите схему этого зала.']],
                'Схема не относится к выбранному залу.'
            );
        }

        if ($schema->status !== SchemaVersionState::PUBLISHED) {
            throw new ValidationError(
                ['schema_version_id' => ['Выбранная версия схемы не опубликована — сначала опубликуйте её в конструкторе.']],
                'Схема не опубликована.'
            );
        }
    }

    /**
     * Инвентарь сеанса, у которого уже есть продажи или активные корзины, нельзя пересобрать.
     *
     * `generateFromSchema()` снимает инвентарь СВОЕГО сеанса целиком
     * (`inventory_items.session_id`), а `tickets.inventory_item_id`,
     * `order_items.inventory_item_id` и `seat_holds.inventory_item_id` ссылаются
     * на эти строки. Без этой проверки смена схемы сеанса с продажами падала
     * FK-ошибкой → 500 «Internal server error», а в лучшем случае оставляла бы
     * билеты, указывающие на несуществующие места. 409: запрос корректен, отказ
     * вызван состоянием ресурса (конвенция `VenueController::destroy`).
     */
    private function assertInventoryRegenerable(Session $session): void
    {
        app(\Nabilet\Modules\Inventory\Services\InventoryService::class)->assertRegenerable($session);
    }


    /** Удаление сеанса. Отменяем, если есть продажи — но в MVP удаляем каскадно. */
    public function destroy(Session $session): JsonResponse
    {
        $session->delete();

        return response()->json(['success' => true, 'message' => 'Session deleted successfully']);
    }
}
