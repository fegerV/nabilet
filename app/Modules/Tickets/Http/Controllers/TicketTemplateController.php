<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Http\Controllers;

use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Http\ContentType;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Tickets\Domain\TicketTemplateConfig;
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
        private readonly \Nabilet\Modules\Tickets\Services\TicketPdfRenderer $renderer,
        private readonly \Nabilet\Modules\Tickets\Services\TicketGeneratorService $generator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->context->idOrNull();

        // Без организации список ПУСТ. Поставить сюда `where('organization_id',
        // null)` нельзя: Laravel превращает такое условие в `IS NULL`, и
        // вместо пустого списка вернулись бы макеты без организации — то есть
        // молча другой набор строк, а не отказ.
        if ($organizationId === null) {
            return response()->json(['data' => []]);
        }

        $query = TicketTemplate::query()
            ->where('organization_id', $organizationId)
            // Привязки к мероприятиям — для списка `/admin/ticket-templates`:
            // какой макет чьи билеты оформляет. Колонки ограничены тем, что
            // рисует карточка (id + title); `ticket_template_id` в выборке
            // обязателен — иначе связь не сопоставится.
            ->with(['events' => fn ($q) => $q->select('id', 'title', 'ticket_template_id')])
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
        $model = $this->find($template);
        $model->load(['events' => fn ($q) => $q->select('id', 'title', 'ticket_template_id')]);

        return response()->json([
            'data' => new TicketTemplateResource($model),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $template = TicketTemplate::query()->create([
            'organization_id' => $this->requireOrganizationId(),
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
        $validated = $request->validate([
            'base_updated_at' => ['required', 'string', 'max:64'],
        ]);
        $model = $this->find($template);

        // Конкурентное редактирование: два администратора открыли один макет
        // и по очереди сохранили его — последний выигрывал молча. Теперь
        // устаревший редактор получает 409 и может экспортировать свои правки.
        $actual = $model->updated_at?->toIso8601String() ?? '';
        if ($actual !== (string) $validated['base_updated_at']) {
            throw new ConflictError(
                'Макет изменён другим редактором. Перезагрузите его перед сохранением.',
                'TICKET_TEMPLATE_CONFLICT',
                ['expected' => $validated['base_updated_at'], 'current' => $actual],
            );
        }

        $data = $this->validatePayload($request, partial: true);
        unset($data['base_updated_at']);

        // `organization_id` не обновляем: шаблон принадлежит организации, и
        // перенос между ними — это не «правка макета».
        $model->update($data);

        return response()->json(['data' => new TicketTemplateResource($model->fresh() ?? $model)]);
    }

    /**
     * Серверный предпросмотр на тестовых данных.
     *
     * Использует ТОТ ЖЕ рендерер, что и билет покупателя: HTML/PNG/PDF строятся
     * из нормализованного TicketTemplateConfig. QR — по заведомо недействительному
     * payload, на выходе билет помечен «ОБРАЗЕЦ».
     */
    public function preview(Request $request, int $template): \Symfony\Component\HttpFoundation\Response
    {
        $model = $this->find($template);
        $format = (string) $request->get('format', 'html');
        $config = TicketTemplateConfig::normalize(
            is_array($model->template_json) ? $model->template_json : [],
            (int) $model->width,
            (int) $model->height,
        );
        // Маркировка образца — явный элемент поверх макета; в настоящий билет
        // она никогда не попадает.
        $config['elements'][] = [
            'id' => 'sample-watermark',
            'type' => 'text',
            'z' => 100000,
            'x' => 10,
            'y' => 10,
            'width' => (int) $config['canvas']['width'] - 20,
            'height' => 30,
            'content' => 'ОБРАЗЕЦ',
            'color' => '#dc2626',
            'fontSize' => 20,
            'fontWeight' => 'bold',
            'rotation' => 0,
            'opacity' => 1,
            'visible' => true,
            'when' => null,
        ];
        $data = $this->generator->previewData();

        return match ($format) {
            'pdf' => response(
                $this->renderer->renderFromConfig($config, $data) ?? '',
                200,
                ['Content-Type' => ContentType::PDF, 'Content-Disposition' => 'inline; filename="ticket-sample.pdf"'],
            ),
            'png' => response(
                $this->renderer->renderPngFromConfig($config, $data, (int) $model->organization_id) ?? '',
                200,
                ['Content-Type' => ContentType::PNG, 'Content-Disposition' => 'inline; filename="ticket-sample.png"'],
            ),
            default => response(
                $this->renderer->renderHtmlFromConfig($config, $data),
                200,
                ['Content-Type' => ContentType::HTML],
            ),
        };
    }

    /**
     * Дублировать макет — «свой билет для этого мероприятия».
     *
     * Сценарий: у мероприятия уже есть макет (по умолчанию или выбранный),
     * администратор хочет свою типографику и фон, не трогая исходник, которым
     * пользуются другие мероприятия. Копия наследует `template_json` целиком —
     * дальше её правят в конструкторе.
     *
     * `event_id` сразу назначает копию мероприятию (той же организации, иначе
     * 422 — чужой бренд в чужие билеты, как в `StoreEventRequest`). Без него —
     * просто копия в списке.
     */
    public function duplicate(Request $request, int $template): JsonResponse
    {
        $source = $this->find($template);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'event_id' => ['bail', 'nullable', 'integer'],
        ]);

        $name = trim((string) ($data['name'] ?? ''));

        // Сначала проверяем событие: раньше копия создавалась до проверки
        // event_id, поэтому запрос с чужим событием завершался 422, но оставлял
        // в списке организатора лишний макет.
        $event = null;
        if (array_key_exists('event_id', $data) && $data['event_id'] !== null) {
            $event = \Nabilet\Modules\Events\Models\Event::query()
                ->whereKey((int) $data['event_id'])
                ->where('organization_id', $this->requireOrganizationId())
                ->first();

            if ($event === null) {
                throw new ValidationError(
                    ['event_id' => ['Мероприятие не найдено в вашей организации.']],
                    'Некорректное мероприятие.'
                );
            }
        }

        $copy = DB::transaction(function () use ($source, $name, $event): TicketTemplate {
            $copy = TicketTemplate::query()->create([
                'organization_id' => $source->organization_id,
                'name' => $name !== '' ? $name : $source->name . ' (копия)',
                'format' => $source->format,
                'width' => $source->width,
                'height' => $source->height,
                // PHP-массивы копируются по значению: правка копии исходник не заденет.
                'template_json' => $source->template_json,
                'active' => $source->active,
            ]);

            if ($event !== null) {
                $event->update(['ticket_template_id' => $copy->id]);
            }

            return $copy;
        });

        $copy->load(['events' => fn ($q) => $q->select('id', 'title', 'ticket_template_id')]);

        return response()->json(['data' => new TicketTemplateResource($copy)], 201);
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

            if (strlen(json_encode($config, JSON_UNESCAPED_UNICODE) ?: '') > 2_000_000) {
                throw new ValidationError(
                    ['template_json' => ['Макет слишком большой (лимит 2 МБ).']],
                    'Некорректный макет шаблона.'
                );
            }

            // Единственный источник геометрии — канонический canvas. Поля
            // width/height в модели синхронизируются с ним, а не наоборот.
            $canonical = TicketTemplateConfig::normalize(
                $config,
                isset($data['width']) ? (int) $data['width'] : null,
                isset($data['height']) ? (int) $data['height'] : null,
            );
            TicketTemplateConfig::validate($canonical);
            $data['template_json'] = $canonical;
            $data['width'] = (int) $canonical['canvas']['width'];
            $data['height'] = (int) $canonical['canvas']['height'];
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
    /**
     * Организация из контекста middleware'а, либо `null`.
     *
     * ЗДЕСЬ БЫЛ ФОЛБЭК НА ПЕРВУЮ ОРГАНИЗАЦИЮ В ТАБЛИЦЕ (и `?? 0`, если таблица
     * пуста) — тот же дефект, что в `EventController::store()` и
     * `MediaController`: сотрудник без членства молча создавал макет в чужом
     * тенанте, а при пустой таблице писал `organization_id = 0`, то есть макет,
     * не принадлежащий никому и невидимый никому.
     *
     * Контекст заполняет `ResolveOrganizationContext` членством пользователя
     * (см. `UserOrganization`), поэтому для сотрудника с членством значение
     * здесь есть. Отсутствие контекста означает, что определять нечего.
     *
     * Вызывающие обрабатывают `null` по fail-closed: список — пустым, запись —
     * отказом, чужой id — 404.
     */

    /**
     * Организация запроса, отказ если её не определить.
     *
     * Для путей записи: макет без организации — это макет ничьей витрины.
     */
    private function requireOrganizationId(): int
    {
        $organizationId = $this->context->idOrNull();

        if ($organizationId === null) {
            throw new ValidationError([
                'organization_id' => ['No organization to attach this template to.'],
            ]);
        }

        return $organizationId;
    }

    /**
     * Найти шаблон, не выходя за пределы организации.
     *
     * Чужой id — 404, а не 403: 403 подтвердил бы существование шаблона другой
     * организации (перебор/IDOR, см. `NotFoundError`).
     */
    private function find(int $id): TicketTemplate
    {
        $organizationId = $this->context->idOrNull();

        if ($organizationId === null) {
            throw new NotFoundError('TicketTemplate', (string) $id);
        }

        $template = TicketTemplate::query()
            ->where('id', $id)
            ->where('organization_id', $organizationId)
            ->first();

        if ($template === null) {
            throw new NotFoundError('TicketTemplate', (string) $id);
        }

        return $template;
    }
}
