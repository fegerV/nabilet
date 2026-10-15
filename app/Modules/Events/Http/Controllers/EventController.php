<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Controllers;

use Nabilet\Core\Support\StaffRole;
use Nabilet\Modules\Content\Models\Redirect;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Http\Requests\StoreEventRequest;
use Nabilet\Modules\Events\Http\Requests\UpdateEventRequest;
use Nabilet\Modules\Events\Http\Resources\EventResource;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Services\EventPublicationService;
use Nabilet\Modules\Events\Services\EventService;
use Nabilet\Modules\Seo\Services\RedirectResolver;
use Nabilet\Modules\Tickets\Services\TicketTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EventController extends Controller
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly EventPublicationService $publication,
        private readonly RedirectResolver $redirects
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['organization_id', 'category_id', 'status', 'is_featured']);

        // ПУБЛИЧНЫЙ КАТАЛОГ НЕ ОТДАЁТ НЕОПУБЛИКОВАННОЕ.
        //
        // Раньше `status` применялся только если его явно прислали, а
        // `GET /api/v1/events` доступен анонимно — то есть запрос без параметров
        // возвращал ВСЕ строки таблицы: черновики, отложенные и отменённые.
        // Витрина их рисует, поисковик индексирует, а JSON-ответ расходится с
        // sitemap (там только `publiclyVisible()`). Это и утечка неопубликованного
        // контента, и источник дублей.
        //
        // Для сотрудника поведение не меняется: админка ходит с токеном и
        // запрашивает конкретный статус (например, `?status=draft` для списка
        // черновиков), и это по-прежнему работает. Ограничение включается ровно
        // тогда, когда запрос анонимный или от покупателя.
        if (! $this->maySeeUnpublished($request)) {
            unset($filters['status']);
            $filters['statuses'] = EventStatus::publiclyVisible();
        }

        $perPage = (int) $request->get('per_page', 20);
        
        $events = $this->eventService->paginate($filters, $perPage);
        
        return response()->json([
            'data' => EventResource::collection($events),
            'meta' => [
                'current_page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
            ],
        ]);
    }

    /**
     * Имеет ли право запрашивающий видеть неопубликованные события.
     *
     * Через `StaffRole`, а не через проверку роли вручную: это та же функция,
     * которой пользуется middleware `admin` и контроллеры заказов, — одна
     * трактовка «кто такой сотрудник» на весь проект. Список ролей, продублированный
     * здесь, разошёлся бы с ней при первом же изменении.
     */
    private function maySeeUnpublished(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && StaffRole::isStaff($user);
    }

    public function show(Event $event): JsonResponse
        {
            $event->load(['category', 'translations', 'sessions']);

            return response()->json([
                'data' => new EventResource($event),
            ]);
        }

        public function showBySlug(string $slug): JsonResponse
            {
                $event = $this->eventService->findBySlug($slug);

                if ($event === null) {
                    // Удалённое событие — 410, а не 404 (аудит P-24). Витрина
                    // различает эти случаи текстом ошибки, а поисковик — снятием
                    // URL из индекса без повторных обходов.
                    if ($this->eventService->findTrashedBySlug($slug) !== null) {
                        return response()->json([
                            'error' => [
                                'code' => 'EVENT_GONE',
                                'message' => sprintf('Мероприятие с адресом «%s» удалено.', $slug),
                            ],
                        ], 410);
                    }

                    return response()->json([
                        'error' => [
                            'code' => 'EVENT_NOT_FOUND',
                            'message' => sprintf('Мероприятие с адресом «%s» не найдено.', $slug),
                        ],
                    ], 404);
                }

                $event->load(['category', 'translations', 'sessions.hall', 'sessions.inventoryItems', 'organization', 'galleryLinks.mediaAsset', 'artists']);

                        $resource = new EventResource($event);
                        $data = $resource->toArray(request());

                // Сеансы — покупательским контрактом Vue: id, starts_at, hall, available_seats.
                                $data['sessions'] = $event->sessions
                                    ->map(fn ($s) => [
                                        'id' => $s->id,
                                        'starts_at' => $s->starts_at?->toIso8601String(),
                                        'hall' => $s->hall?->name,
                                        'available_seats' => $s->inventoryItems
                                            ->filter(fn ($i) => $i->status === 'available' && ($i->available_quantity ?? 0) > 0)
                                            ->count(),
                                    ])
                                    ->toArray();

                // Дополнительные фото и видео — прямо в публичном ответе, а не
                // отдельным запросом. Отдельный эндпоинт потребовал бы второго
                // круга к серверу на каждое открытие страницы события, ради
                // данных, которые нужны ровно на этой странице и больше нигде.
                //
                // Файлы с `deleted_at` отсеиваются: связь при мягком удалении
                // файла снимается, но полагаться на это здесь нельзя — иначе
                // страница покажет карточку без картинки.
                $data['gallery'] = $event->galleryLinks
                    ->filter(fn ($link) => $link->mediaAsset !== null)
                    ->map(fn ($link) => [
                        'id' => $link->mediaAsset->id,
                        'url' => $link->mediaAsset->url(),
                        'filename' => $link->mediaAsset->filename,
                        'mime_type' => $link->mediaAsset->mime_type,
                        'width' => $link->mediaAsset->width,
                        'height' => $link->mediaAsset->height,
                        'alt_text' => $link->mediaAsset->alt_text,
                        'position' => $link->position,
                    ])
                    ->values()
                    ->toArray();

                // Исполнители (стратегия SEO, B-1) — ТОЛЬКО имя и адрес.
                //
                // ССЫЛКА НА АРТИСТА — ГЛАВНАЯ ПЕРЕЛИНКОВКА ВСЕЙ СЕРИИ. Страница
                // события — самый обходимый тип страниц: краулер приходит на неё
                // из карты сайта и из выдачи. Без ссылки отсюда страницы
                // артистов были бы достижимы ТОЛЬКО через `sitemap-artists.xml`,
                // то есть вес на них не перетекал бы вовсе — карта сообщает о
                // существовании адреса, но не передаёт авторитет.
                //
                // БЕЗ `bio`, `photo` И ПРОЧЕГО ПРОФИЛЯ. Это ответ ВИТРИНЫ: она
                // показывает список исполнителей и ведёт на их страницы, а
                // карточка артиста целиком (биография, жанр, соцсети) живёт на
                // `/artist/{slug}` и там же индексируется. Копия профиля в
                // ответе разошлась бы с той страницей при первой правке.
                //
                // `status = 'active'` уже отсеян связью `Event::artists()` —
                // ссылка на снятого с публикации артиста вела бы на 404.
                $data['artists'] = $event->artists
                    ->map(fn ($artist) => [
                        'name' => (string) $artist->name,
                        'slug' => (string) $artist->slug,
                    ])
                    ->values()
                    ->toArray();

                return response()->json([
                    'data' => $data,
                ]);
            }

    public function store(StoreEventRequest $request): JsonResponse
        {
            $data = $request->validated();

            // БД требует organization_id NOT NULL; форма его не шлёт — берём
            // организацию пользователя (или первую в системе), как в VenueController.
            if (empty($data['organization_id'])) {
                $orgId = $request->user()?->organizations()->first()?->id;
                $data['organization_id'] = $orgId ?? \Nabilet\Modules\Core\Organizations\Models\Organization::query()->value('id');
            }

            // Макет билета по умолчанию. Новое мероприятие без выбранного макета
            // получает первый активный макет организации (а если макетов нет
            // вообще — созданный «Стандартный»), а не NULL: первое мероприятие
            // обычно появляется раньше первого собранного шаблона, и билеты
            // не должны уходить неоформленными. Явный выбор администратора не
            // трогаем; снять назначение позже можно выбором «Стандартный вид».
            //
            // Вызов идёт напрямую в сервис Tickets, а не через `requires` в
            // module.json: туда его добавить нельзя — cart требует events, а
            // tickets требует orders/cart, получился бы цикл
            // events → tickets → orders → cart → events. Прецедент связи уже
            // есть (`Event::ticketTemplate()`), направление то же.
            if (empty($data['ticket_template_id']) && ! empty($data['organization_id'])) {
                $data['ticket_template_id'] = app(TicketTemplateService::class)
                    ->ensureDefaultTemplate((int) $data['organization_id'])
                    ->id;
            }

            $event = $this->eventService->create($data);
        
        return response()->json([
            'data' => new EventResource($event->fresh()),
            // Поля, которые клиент прислал, но записать их некуда (в `events`
            // нет колонок). Возвращаем их имена, чтобы админка могла сказать
            // «эти поля проигнорированы», а не показывать успешное сохранение
            // поверх молча потерянных значений.
            'meta' => [
                'ignored_fields' => array_keys($request->droppedFields()),
            ],
        ], 201);
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        $data = $request->validated();

        // Прежний адрес запоминаем ДО записи: после `update()` взять его негде —
        // `$event` уже перезаписан новым slug.
        $previousSlug = $event->slug;

        $event = $this->eventService->update($event, $data);

        $this->rememberSlugChange($previousSlug, $event);

        return response()->json([
            'data' => new EventResource($event->fresh()),
            'meta' => [
                'ignored_fields' => array_keys($request->droppedFields()),
            ],
        ]);
    }

    /**
     * Записать постоянный редирект со старого адреса события на новый.
     *
     * БЕЗ ЭТОГО ПЕРЕИМЕНОВАНИЕ = ПОТЕРЯ СТРАНИЦЫ. `slug` — это и есть публичный
     * адрес (`/event/{slug}/{publicId}`). Меняя его, администратор обрывал все
     * накопленные внешние ссылки и позиции: старый адрес начинал отдавать 404, и
     * восстановить трафик было нечем — таблица `redirects` существовала, но
     * никто в неё не писал.
     *
     * Нормализация берётся из `RedirectResolver`, а не повторяется здесь: тот же
     * метод применяется при ПОИСКЕ редиректа. Две копии правила разошлись бы, и
     * запись перестала бы находиться (например, при разнице в хвостовом слэше).
     *
     * `updateOrCreate`, а не `create`: slug можно вернуть обратно, и повторная
     * запись обязана перезаписать цель, а не упасть на UNIQUE(source).
     */
    private function rememberSlugChange(string $previousSlug, Event $event): void
    {
        if ($previousSlug === '' || $previousSlug === $event->slug) {
            return;
        }

        $source = $this->redirects->normalize('/event/'.$previousSlug);
        $destination = $this->redirects->normalize(
            '/event/'.$event->slug.'/'.$event->public_id
        );

        // Назначение совпало с источником — запись бессмысленна и опасна
        // (самопереход). Практически невозможно (slug изменился), но проверка
        // стоит один `if` и закрывает опечатку в будущей правке.
        if ($source === $destination) {
            return;
        }

        Redirect::query()->updateOrCreate(
            ['source' => $source],
            [
                'destination' => $destination,
                'status_code' => 301,
                'active' => true,
            ]
        );
    }

    public function destroy(Event $event): JsonResponse
    {
        $this->eventService->delete($event);
        
        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/events/{event}/publish
     *
     * Единственный путь к статусу `published`. До этого эндпоинта публикация
     * выполнялась присланным в теле `status = 'published'`, и проверки
     * готовности (`EventPublicationPolicy`) не запускались вообще — событие без
     * сеансов попадало на витрину и в sitemap как «тонкая страница».
     *
     * 409, а не 422: запрос синтаксически корректен, отказ вызван состоянием
     * ресурса (нет сеансов, уже удалено). 422 в §66 envelope означает ошибку
     * валидации полей, и путать эти случаи — значит ломать обработку на клиенте.
     */
    public function publish(Event $event): JsonResponse
    {
        $decision = $this->publication->publish($event);

        return response()->json([
            'data' => new EventResource($event->fresh()),
            'meta' => [
                'verdict' => $decision->verdict,
                // `no_change` — событие уже было опубликовано; запись не
                // производилась, `updated_at` не менялся.
                'changed' => $decision->requiresWrite(),
            ],
        ]);
    }

    /**
     * POST /api/v1/events/{event}/cancel
     *
     * Отмена блокируется, если по событию есть оплаченные заказы: билеты нельзя
     * аннулировать раньше, чем покупателям вернут деньги (ТЗ §13).
     */
    public function cancel(Event $event): JsonResponse
    {
        $decision = $this->publication->cancel($event);

        return response()->json([
            'data' => new EventResource($event->fresh()),
            'meta' => [
                'verdict' => $decision->verdict,
                'changed' => $decision->requiresWrite(),
            ],
        ]);
    }
}
