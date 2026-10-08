<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Http\Controllers;

use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Tickets\Http\Resources\TicketTemplateResource;
use Nabilet\Modules\Tickets\Models\TicketTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Шаблоны билетов (админка).
 *
 * ДО ЭТОГО КОНТРОЛЛЕРА КОНСТРУКТОР БЫЛ НЕДОСТИЖИМ
 *
 * `TicketBuilder.vue` (1252 строки) умел рисовать макет, но ни одного роута
 * для `ticket_templates` в проекте не существовало: сохранение уходило в 404,
 * список был захардкожен в компоненте, а страницы, которая бы его монтировала,
 * не было вовсе. То есть вся функция существовала только в виде файла.
 *
 * ТАБЛИЦА УЖЕ ЕСТЬ
 *
 * `ticket_templates` объявлена в пакете (`nabilet_core_spec/migrations.sql:555`)
 * и присутствует в БД: public_id, organization_id, name, format, width, height,
 * template_json, active. Миграция НЕ нужна — нужен был только доступ по HTTP.
 *
 * ПОЧЕМУ `template_json` НЕ ТИПИЗИРУЕТСЯ
 *
 * Это произвольный JSON: набор элементов холста, их координаты и стили. Схема
 * конструктора меняется чаще, чем что-либо ещё в проекте, и жёсткая валидация
 * структуры означала бы миграцию на каждое новое свойство элемента. Проверяем
 * только то, без чего запись бессмысленна: что это объект и что `elements` —
 * массив. Всё остальное — дело конструктора.
 */
class TicketTemplateController extends Controller
{
    /** Предел на размер макета: защита от записи мегабайтов в JSON-колонку. */
    private const MAX_ELEMENTS = 300;

    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = TicketTemplate::query()
            ->where('organization_id', $this->organizationId())
            ->orderByDesc('active')
            ->orderBy('name');

        // `active=1` — фильтр для формы мероприятия: там нужны только шаблоны,
        // которые можно назначить, а не черновики.
        if ($request->boolean('active_only')) {
            $query->where('active', true);
        }

        return response()->json([
            'data' => TicketTemplateResource::collection($query->get()),
        ]);
    }

    public function show(int $template): JsonResponse
    {
        return response()->json([
            'data' => new TicketTemplateResource($this->find($template)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $template = TicketTemplate::query()->create([
            'organization_id' => $this->organizationId(),
            'name' => $data['name'],
            'format' => $data['format'] ?? 'mobile',
            'width' => $data['width'],
            'height' => $data['height'],
            'template_json' => $data['template_json'],
            'active' => $data['active'] ?? true,
        ]);

        return response()->json(['data' => new TicketTemplateResource($template)], 201);
    }

    public function update(Request $request, int $template): JsonResponse
    {
        $model = $this->find($template);
        $data = $this->validatePayload($request, partial: true);

        // `organization_id` не обновляем: шаблон принадлежит организации, и
        // перенос между ними — это не «правка макета».
        $model->update($data);

        return response()->json(['data' => new TicketTemplateResource($model->fresh() ?? $model)]);
    }

    /**
     * Удаление шаблона.
     *
     * Физическое, а не `active = 0`: администратор нажал «удалить», и оставлять
     * запись, которая просто исчезла из списка, значит копить мусор, который
     * потом никто не найдёт. События ссылаются на шаблон через
     * `events.ticket_template_id` с `ON DELETE SET NULL`, поэтому удаление не
     * ломает мероприятия — они просто возвращаются к шаблону по умолчанию.
     */
    public function destroy(int $template): JsonResponse
    {
        $model = $this->find($template);

        DB::transaction(static function () use ($model): void {
            $model->delete();
        });

        return response()->json(['data' => ['id' => (int) $model->id, 'deleted' => true]]);
    }

    /**
     * Валидация payload макета.
     *
     * `partial: true` — для PATCH/PUT с частичным набором полей. Конструктор
     * всегда шлёт всё целиком, но административный API не должен требовать
     * `template_json`, чтобы переименовать шаблон.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'format' => ['sometimes', 'string', 'max:32'],
            'width' => [$required, 'integer', 'min:50', 'max:5000'],
            'height' => [$required, 'integer', 'min:50', 'max:5000'],
            'template_json' => [$required, 'array'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('template_json', $data)) {
            $config = $data['template_json'];

            // `elements` обязателен: макет без элементов — это пустой билет.
            if (! array_key_exists('elements', $config) || ! is_array($config['elements'])) {
                throw new ValidationError(
                    ['template_json' => ['Поле elements обязательно и должно быть массивом.']],
                    'Некорректный макет шаблона.'
                );
            }

            if (count($config['elements']) > self::MAX_ELEMENTS) {
                throw new ValidationError(
                    ['template_json' => [sprintf('Слишком много элементов: максимум %d.', self::MAX_ELEMENTS)]],
                    'Некорректный макет шаблона.'
                );
            }
        }

        return $data;
    }

    /**
     * Организация шаблона.
     *
     * НЕ полагается на `OrganizationContext::id()`: `NabiletServiceProvider` не
     * зарегистрирован в `bootstrap/providers.php`, поэтому контекст не связан
     * как singleton и `app(OrganizationContext::class)` даёт каждый раз НОВЫЙ
     * экземпляр — без данных запроса. Фолбэк «первая организация по id» — тот
     * же приём, что в `StorefrontController::publicOrganizationId()`.
     */
    private function organizationId(): int
    {
        $contextId = $this->context->tryId();

        if ($contextId !== null && ctype_digit((string) $contextId)) {
            return (int) $contextId;
        }

        return (int) (\Nabilet\Modules\Core\Organizations\Models\Organization::query()
            ->orderBy('id')
            ->value('id') ?? 0);
    }

    /**
     * Найти шаблон, не выходя за пределы организации.
     *
     * Чужой id — 404, а не 403: 403 подтвердил бы существование шаблона другой
     * организации (перебор/IDOR, см. `NotFoundError`).
     */
    private function find(int $id): TicketTemplate
    {
        $template = TicketTemplate::query()
            ->where('id', $id)
            ->where('organization_id', $this->organizationId())
            ->first();

        if ($template === null) {
            throw new NotFoundError('TicketTemplate', (string) $id);
        }

        return $template;
    }
}
