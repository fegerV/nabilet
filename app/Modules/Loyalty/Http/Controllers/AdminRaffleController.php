<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Nabilet\Modules\Core\Errors\NotFoundError;
use Nabilet\Modules\Loyalty\Jobs\NotifyRaffleWinnersJob;
use Nabilet\Modules\Loyalty\Models\Raffle;
use Nabilet\Modules\Loyalty\Services\RaffleService;

/**
 * Розыгрыши «счастливый билет» из админки (фаза 5).
 *
 * Только сотрудник (`admin` поверх `auth:api`): тираж распределяет призы, а
 * активация и отмена меняют состав участников — это операции организатора, а
 * не покупателя.
 *
 * ПОЧЕМУ РОЗЫГРЫШ ИЩЕТСЯ ПО `public_id`
 *   Соглашение проекта: внутренний BIGINT не покидает API
 *   (`DATABASE.md` §9). Неявная привязка модели привела бы строку к int и
 *   открыла бы `/admin/raffles/1`, поэтому метод принимает строку и разрешает
 *   сам — тот же приём, что у `LoyaltyController::resolveEvent()`.
 *
 * ПОЧЕМУ ДЕЙСТВИЯ — ОТДЕЛЬНЫЕ ПУТИ, А НЕ ПОЛЕ `status` В ТЕЛЕ
 *   Активация, отмена и тираж — не правка полей: у каждой свои побочные
 *   эффекты (backfill в очередь, запрет правки призов, рассылка писем
 *   победителям). Смена статуса через `PUT` обходила бы их, поэтому `status`
 *   в теле запроса на осознанно не принимается — только через действия.
 */
class AdminRaffleController extends Controller
{
    public function __construct(
        private readonly RaffleService $raffles,
    ) {
    }

    /**
     * GET /api/v1/admin/raffles — список розыгрышей со счётчиками.
     *
     * Счётчики собираются одним проходом (`withCount`), а не в цикле: список
     * розыгрышей на живой базе — десятки строк, и N+1 здесь виден сразу.
     */
    public function index(): JsonResponse
    {
        $raffles = Raffle::query()
            ->withCount(['prizes', 'entries', 'winners'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Raffle $raffle): array => [
                'public_id' => $raffle->public_id,
                'title' => $raffle->title,
                'status' => $raffle->status,
                'starts_at' => $raffle->starts_at,
                'ends_at' => $raffle->ends_at,
                'drawn_at' => $raffle->drawn_at,
                'prizes_count' => (int) $raffle->prizes_count,
                'entries_count' => (int) $raffle->entries_count,
                'winners_count' => (int) $raffle->winners_count,
                'created_at' => $raffle->created_at,
            ]);

        return response()->json(['data' => $raffles]);
    }

    /** GET /api/v1/admin/raffles/{raffle} — розыгрыш с призами и победителями. */
    public function show(string $raffle): JsonResponse
    {
        return response()->json(['data' => $this->present($this->resolve($raffle))]);
    }

    /**
     * POST /api/v1/admin/raffles — создать черновик с призами.
     *
     * Статус намеренно не принимается: розыгрыш рождается `draft`, и
     * активация — отдельное действие с backfill.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, prizesRequired: true);

        $raffle = $this->raffles->create($data['attributes'], $data['prizes']);

        return response()->json(['data' => $this->present($raffle)], 201);
    }

    /** PUT /api/v1/admin/raffles/{raffle} — правка полей и призов. */
    public function update(Request $request, string $raffle): JsonResponse
    {
        $model = $this->resolve($raffle);
        $data = $this->validated($request, prizesRequired: false);

        $model = $this->raffles->update($model, $data['attributes'], $data['prizes']);

        return response()->json(['data' => $this->present($model)]);
    }

    /** POST /api/v1/admin/raffles/{raffle}/activate — начать набор участников. */
    public function activate(string $raffle): JsonResponse
    {
        $model = $this->raffles->activate($this->resolve($raffle));

        return response()->json(['data' => $this->present($model)]);
    }

    /** POST /api/v1/admin/raffles/{raffle}/cancel — закрыть розыгрыш без тиража. */
    public function cancel(string $raffle): JsonResponse
    {
        $model = $this->raffles->cancel($this->resolve($raffle));

        return response()->json(['data' => $this->present($model)]);
    }

    /**
     * POST /api/v1/admin/raffles/{raffle}/draw — провести тираж.
     *
     * Отказы приходят кодами (`RAFFLE_ALREADY_DRAWN`, `RAFFLE_NOT_ACTIVE`,
     * `RAFFLE_NO_PRIZES`, `RAFFLE_NOT_ENOUGH_ENTRIES`): их бросает сервис
     * (`DomainRuleViolation`), а `ApiExceptionRenderer` превращает в конверт §66.
     * Ответ — победители по местам: организатор видит результат сразу, не
     * открывая отдельную страницу.
     */
    public function draw(string $raffle): JsonResponse
    {
        $winners = $this->raffles->draw($this->resolve($raffle));

        return response()->json([
            'data' => $winners->map(fn ($winner): array => [
                'place' => (int) ($winner->prize?->place ?? 0),
                'prize_title' => (string) ($winner->prize?->title ?? ''),
                'order_number' => (string) ($winner->entry?->order?->order_number ?? ''),
                'customer_email' => (string) ($winner->entry?->order?->customer_email ?? ''),
                'notified' => $winner->isNotified(),
            ])->values(),
        ]);
    }

    /**
     * POST /api/v1/admin/raffles/{raffle}/notify — разослать письма победителям.
     *
     * Повторная отправка: тираж ставит `NotifyRaffleWinnersJob` сам, и этот
     * путь нужен, когда SMTP падал и `notified_at` остался пустым. Отправляет
     * только неуведомлённым: кому письмо уже ушло — тому второй раз не шлём.
     */
    public function notify(string $raffle): JsonResponse
    {
        $model = $this->resolve($raffle);
        $pending = $this->raffles->winnersPendingNotification($model);

        NotifyRaffleWinnersJob::forRaffle($model)
            ->onConnection('database')
            ->afterCommit();

        return response()->json([
            'data' => [
                'raffle' => $model->public_id,
                'pending' => $pending->count(),
            ],
        ]);
    }

    /** Разрешить розыгрыш по публичному идентификатору. */
    private function resolve(string $publicId): Raffle
    {
        $raffle = $this->raffles->findByPublicId($publicId);

        if ($raffle === null) {
            throw new NotFoundError('Raffle', $publicId);
        }

        return $raffle;
    }

    /**
     * Розыгрыш с призами и победителями — единая форма ответа админки.
     *
     * @return array<string, mixed>
     */
    private function present(Raffle $raffle): array
    {
        $raffle = $this->raffles->results($raffle);
        // Счётчик билетов: экран тиража показывает, хватит ли участников на
        // призовые места, до нажатия кнопки. Отдельный `loadCount`, а не
        // загрузка всех билетов: их могут быть тысячи, а число — одно.
        $raffle->loadCount('entries');

        return [
            'public_id' => $raffle->public_id,
            'title' => $raffle->title,
            'description' => $raffle->description,
            'status' => $raffle->status,
            'starts_at' => $raffle->starts_at,
            'ends_at' => $raffle->ends_at,
            'drawn_at' => $raffle->drawn_at,
            'created_at' => $raffle->created_at,
            'updated_at' => $raffle->updated_at,
            'entries_count' => (int) $raffle->entries_count,
            'prizes' => $raffle->orderedPrizes->map(fn ($prize): array => [
                'public_id' => $prize->public_id,
                'place' => (int) $prize->place,
                'title' => $prize->title,
                'description' => $prize->description,
            ])->values(),
            'winners' => $raffle->winners->map(fn ($winner): array => [
                'public_id' => $winner->public_id,
                'place' => (int) ($winner->prize?->place ?? 0),
                'prize_title' => (string) ($winner->prize?->title ?? ''),
                'order_number' => (string) ($winner->entry?->order?->order_number ?? ''),
                'customer_email' => (string) ($winner->entry?->order?->customer_email ?? ''),
                'notified' => $winner->isNotified(),
            ])->values(),
        ];
    }

    /**
     * Валидация создания и правки розыгрыша.
     *
     * ПОЧЕМУ ПРИЗЫ ПРОВЕРЯЮТСЯ ЗДЕСЬ, А НЕ ТОЛЬКО В МОДЕЛИ
     *   Два первых места — не ошибка данных, а ошибка ввода: место 2 некому
     *   вручить, если место 1 тоже «первое». Места должны быть уникальны в
     *   пределах розыгрыша, и UNIQUE (raffle_id, place) в базе это гарантирует
     * * постфактум — а администратору нужно понятное сообщение ДО записи.
     *
     * @return array{attributes: array<string, mixed>, prizes: list<array{place: int, title: string, description: string|null}>}
     */
    private function validated(Request $request, bool $prizesRequired): array
    {
        $data = $request->validate([
            'title' => [$prizesRequired ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Даты окна. `date` принимает ISO-8601, который шлёт SPA.
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'prizes' => [$prizesRequired ? 'required' : 'sometimes', 'array', 'min:1'],
            'prizes.*.place' => ['required_with:prizes', 'integer', 'min:1', 'max:255'],
            'prizes.*.title' => ['required_with:prizes', 'string', 'max:255'],
            'prizes.*.description' => ['nullable', 'string', 'max:2000'],
        ]);

        $prizes = [];

        foreach ((array) ($data['prizes'] ?? []) as $prize) {
            $prizes[] = [
                'place' => (int) $prize['place'],
                'title' => (string) $prize['title'],
                'description' => isset($prize['description']) ? (string) $prize['description'] : null,
            ];
        }

        $this->assertUniquePlaces($prizes);

        // Только те ключи, которые пришли: `fill()` с null затёр бы название
        // при правке, где поля просто нет в теле (`sometimes`).
        $attributes = array_intersect_key($data, array_flip([
            'title', 'description', 'starts_at', 'ends_at',
        ]));

        return [
            'attributes' => $attributes,
            'prizes' => $prizes,
        ];
    }

    /**
     * @param  list<array{place: int, title: string, description: string|null}>  $prizes
     */
    private function assertUniquePlaces(array $prizes): void
    {
        $places = array_column($prizes, 'place');
        sort($places);

        if (count($places) !== count(array_unique($places))) {
            throw ValidationException::withMessages([
                'prizes' => ['Призовые места не должны повторяться: два приза на одно место сделать тираж неопределённым.'],
            ]);
        }
    }
}
