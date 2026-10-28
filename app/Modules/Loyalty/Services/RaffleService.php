<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Support\RuText;
use Nabilet\Modules\Loyalty\Domain\RaffleDraw;
use Nabilet\Modules\Loyalty\Jobs\BackfillRaffleEntriesJob;
use Nabilet\Modules\Loyalty\Jobs\NotifyRaffleWinnersJob;
use Nabilet\Modules\Loyalty\Models\Raffle;
use Nabilet\Modules\Loyalty\Models\RaffleEntry;
use Nabilet\Modules\Loyalty\Models\RafflePrize;
use Nabilet\Modules\Loyalty\Models\RaffleWinner;
use Nabilet\Modules\Loyalty\StateMachines\RaffleStateMachine;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Розыгрыши «счастливый билет» (фаза 5 `docs/LOYALTY-PLAN.md`).
 *
 * СХЕМА РАБОТЫ
 *   Организатор создаёт розыгрыш (`draft`) с призами → активирует: статус
 *   `active`, backfill забирает оплаченные заказы окна → каждая следующая
 *   оплата заходит через `OrderRaffleObserver` → организатор нажимает
 *   «Провести тираж»: `draw()` распределяет билеты по местам и рассылает
 *   победителям письма.
 *
 * ТРИ ВХОДА, ОДНО ПРАВИЛО НАБОРА УЧАСТНИКОВ
 *   Билеты создаются из трёх мест — backfill активации, обсервер оплаты и
 *   (теоретически) ручной вызов — и все три обязаны использовать один
 *   предикат «входит ли оплата в окно». Предикат живёт в чистом
 *   `Domain\RaffleWindow`, идемпотентность — в схеме (`UNIQUE (raffle_id,
 *   order_id)`, ловля конфликта в `RaffleEntry::remember()`). поэтому
 *   повтор backfill или повторная запись статуса `paid` не дублируют
 *   участника.
 *
 * ПОЧЕМУ ТИРАЖ — ТРАНЗАКЦИЯ С БЛОКИРОВКОЙ
 *   Кнопку «Провести тираж» можно нажать дважды (двойной клик, два
 *   организатора). `lockForUpdate()` на строке розыгрыша сериализует
 *   попытки, а `UNIQUE (raffle_id, raffle_prize_id)` — последняя линия:
 *   даже если блокировка не сработала, вторым победителем на место не
 *   стать, конфликт вставки превращается в `RAFFLE_ALREADY_DRAWN`.
 *
 * ПОЧЕМУ БИЛЕТ НЕ ОТЗЫВАЕТСЯ ВОЗВРАТОМ
 *   Ограничение намеренное и то же, что у кэшбэка (фаза 2b) и реферальной
 *   награды (фаза 4): факт участия уже зафиксирован, а удаление строк после
 *   тиража сделало бы результаты невоспроизводимыми. Записано в плане и в
 *   шапке сплита 022, а не спрятано в коде.
 */
final class RaffleService
{
    /**
     * Сколько оплаченных заказов backfill берёт за один запрос.
     *
     * Ограничение памяти, а не логики: розыгрыш «за всё время» перебирает всю
     * таблицу заказов, и `get()` вычитал бы её в память целиком.
     */
    private const BACKFILL_CHUNK = 200;

    public function __construct()
    {
    }

    // ───────────────────────────────────────────────────────── участники ──

    /**
     * Завести билеты для оплаченного заказа во всех подходящих розыгрышах.
     *
     * Вызывается из `EnterRafflesJob` (обсервер оплаты), а не напрямую из
     * обсервера: вставка не должна выполняться внутри транзакции оплаты —
     * сбой розыгрыша не имеет права отменить купленный билет.
     *
     * @return int сколько билетов создано (0 — заказ не подошёл ни одному
     *             розыгрышу, не оплачен или билеты уже были)
     */
    public function enterForOrder(Order $order): int
    {
        if ($order->status !== OrderStateMachine::PAID) {
            return 0;
        }

        $created = 0;

        foreach ($this->acceptingRaffles($this->paidMoment($order)) as $raffle) {
            if (RaffleEntry::remember($raffle->getKey(), $order) !== null) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Добрать билеты для уже оплаченных заказов окна (активация розыгрыша).
     *
     * ЗАЧЕМ BACKFILL, ЕСЛИ ЕСТЬ ОБСЕРВЕР
     *   Розыгрыш «за всё время» или «за сентябрь» создаётся ПОСЛЕ заказов,
     *   которых он касается: обсервер увидел бы только новые оплаты, и
     *   состав участников зависел бы от того, когда организатор нажал
     *   кнопку. Backfill делает набор участников функцией окна, а не
     *   календаря.
     *
     * ИДЕМПОТЕНТНОСТЬ
     *   Повторная активация (пауза `active → draft → active`) перебирает то
     *   же окно заново и не дублирует билеты: `UNIQUE (raffle_id, order_id)`
     *   + ловля конфликта в `RaffleEntry::remember()`.
     *
     * @return int сколько билетов создано
     */
    public function backfill(Raffle $raffle): int
    {
        if ($raffle->status !== Raffle::STATUS_ACTIVE) {
            return 0;
        }

        $created = 0;

        Order::query()
            ->where('status', OrderStateMachine::PAID)
            // Тот же момент, что у обсервера: `paid_at`, а для заказов,
            // оплаченных до появления колонки, — `created_at`. COALESCE
            // держит обсервер и backfill на одном правиле.
            ->where(function ($query) use ($raffle): void {
                $moment = 'COALESCE(paid_at, created_at)';

                if ($raffle->starts_at !== null) {
                    $query->whereRaw($moment . ' >= ?', [$raffle->starts_at]);
                }

                if ($raffle->ends_at !== null) {
                    $query->whereRaw($moment . ' <= ?', [$raffle->ends_at]);
                }
            })
            ->chunkById(self::BACKFILL_CHUNK, function ($orders) use ($raffle, &$created): void {
                foreach ($orders as $order) {
                    if (RaffleEntry::remember($raffle->getKey(), $order) !== null) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    /**
     * Розыгрыши, которые принимают участника на момент `$moment`.
     *
     * Статус отбирается в SQL (индекс `idx_raffles_status`), окно — в PHP:
     * сравнивать DATETIME(6) с микросекундами в SQL можно, но тогда правило
     * живёт в двух местах. Активных розыгрышей десятки, поэтому перебор в
     * памяти дешевле расхождения.
     *
     * @return Collection<int, Raffle>
     */
    public function acceptingRaffles(\DateTimeInterface $moment): Collection
    {
        return Raffle::query()
            ->whereIn('status', RaffleStateMachine::acceptingEntries())
            ->get()
            ->filter(fn (Raffle $raffle): bool => $raffle->covers($moment))
            ->values();
    }

    /**
     * Момент оплаты заказа — тот же, по которому проверяется окно.
     *
     * `paid_at` пишет `OrderService::markPaid()`; NULL бывает у заказов,
     * помеченных оплаченными без него (сиды, админские сценарии), и терять
     * из-за этого участника неправильно.
     */
    private function paidMoment(Order $order): \DateTimeInterface
    {
        return $order->paid_at ?? $order->created_at;
    }

    // ─────────────────────────────────────────────────── жизненный цикл ──

    /**
     * Создать розыгрыш с призами (черновик).
     *
     * Призы синхронизируются по месту: `place` — идентификатор приза в
     * пределах розыгрыша (`update()` находит по нему существующий), поэтому
     * правка названия не пересоздаёт приз и не теряет его `public_id`.
     */
    public function create(array $attributes, array $prizes = []): Raffle
    {
        return DB::transaction(function () use ($attributes, $prizes): Raffle {
            $raffle = Raffle::query()->create($attributes + ['status' => Raffle::STATUS_DRAFT]);

            $this->syncPrizes($raffle, $prizes);

            return $raffle->fresh(['orderedPrizes']) ?? $raffle;
        });
    }

    /**
     * Обновить розыгрыш и (если переданы) его призы.
     *
     * Призы разыгранного розыгрыша недвижимы: `raffle_winners` ссылаются на
     * них, и правка приза после тиража меняла бы смысл выигранного места.
     * Отказ — `RAFFLE_ALREADY_DRAWN`, а не молчание.
     */
    public function update(Raffle $raffle, array $attributes, ?array $prizes = null): Raffle
    {
        return DB::transaction(function () use ($raffle, $attributes, $prizes): Raffle {
            if ($prizes !== null && $raffle->status === Raffle::STATUS_DRAWN) {
                throw new DomainRuleViolation(
                    'Призы разыгранного розыгрыша нельзя изменить: результаты тиража ссылаются на них.',
                    'RAFFLE_ALREADY_DRAWN',
                );
            }

            // Смена статуса возможна только через машину состояний: `fill()`
            // взял бы статус из тела запроса в обход activate()/cancel(), и
            // backfill при активации не запустился бы.
            if (array_key_exists('status', $attributes) && $attributes['status'] !== $raffle->status) {
                $this->assertTransition($raffle, (string) $attributes['status']);
            }

            $raffle->fill($attributes)->save();

            if ($prizes !== null) {
                $this->syncPrizes($raffle, $prizes);
            }

            return $raffle->fresh(['orderedPrizes']) ?? $raffle;
        });
    }

    /**
     * Активировать розыгрыш: `draft → active` + backfill участников.
     *
     * Backfill уходит в очередь (`BackfillRaffleEntriesJob`), а не
     * выполняется в запросе: розыгрыш «за всё время» перебирает всю таблицу
     * заказов, и организатор не должен смотреть на висящий HTTP-запрос.
     * Джоба идемпотентна (см. `backfill()`), поэтому её повтор безопасен.
     */
    public function activate(Raffle $raffle): Raffle
    {
        return DB::transaction(function () use ($raffle): Raffle {
            $this->assertTransition($raffle, Raffle::STATUS_ACTIVE);

            $raffle->forceFill(['status' => Raffle::STATUS_ACTIVE])->save();

            BackfillRaffleEntriesJob::forRaffle($raffle)
                ->onConnection('database')
                ->afterCommit();

            return $raffle->fresh();
        });
    }

    /**
     * Закрыть розыгрыш без тиража: `active|draft → cancelled`.
     *
     * Отмена — не тираж: победителей нет, письма не рассылаются, участия
     * перестают отображаться в кабинете. Сами билеты остаются в таблице —
     * участие было фактом, а отмена отменяет будущий розыгрыш, не прошлую
     * покупку.
     */
    public function cancel(Raffle $raffle): Raffle
    {
        return DB::transaction(function () use ($raffle): Raffle {
            $this->assertTransition($raffle, Raffle::STATUS_CANCELLED);

            $raffle->forceFill(['status' => Raffle::STATUS_CANCELLED])->save();

            return $raffle->fresh();
        });
    }

    /**
     * Провести тираж: распределить билеты по призовым местам.
     *
     * ПОРЯДОК ПРОВЕРОК ВАЖЕН
     *   Сначала блокировка и статус (двойное нажатие), потом состав
     *   участников: иначе первый тираж при нехватке билетов оставил бы
     *   розыгрыш в непонятном состоянии, а отказ по составу — это отказ
     *   без изменений.
     *
     * @return Collection<int, RaffleWinner> победители по местам
     */
    public function draw(Raffle $raffle): Collection
    {
        return DB::transaction(function () use ($raffle): Collection {
            /** @var Raffle|null $locked */
            $locked = Raffle::query()->lockForUpdate()->find($raffle->getKey());

            if ($locked === null) {
                throw new NotFoundError('Raffle', (string) $raffle->getKey());
            }

            if ($locked->status === Raffle::STATUS_DRAWN) {
                throw new DomainRuleViolation(
                    sprintf('Тираж по этому розыгрышу уже проведён (%s).', RuText::stamp($locked->drawn_at) ?? 'дата не записана'),
                    'RAFFLE_ALREADY_DRAWN',
                );
            }

            if ($locked->status !== Raffle::STATUS_ACTIVE) {
                throw new DomainRuleViolation(
                    sprintf('Тираж проводит только активный розыгрыш, а этот — «%s».', $locked->status),
                    'RAFFLE_NOT_ACTIVE',
                );
            }

            $prizes = $locked->orderedPrizes()->get();

            if ($prizes->isEmpty()) {
                throw new DomainRuleViolation(
                    'В розыгрыше нет призовых мест — разыгрывать нечего.',
                    'RAFFLE_NO_PRIZES',
                );
            }

            $entryIds = RaffleEntry::query()->where('raffle_id', $locked->getKey())->pluck('id')->all();

            if (count($entryIds) < $prizes->count()) {
                throw new DomainRuleViolation(
                    sprintf('Билетов %d, а призовых мест %d — тираж несостоятелен.', count($entryIds), $prizes->count()),
                    'RAFFLE_NOT_ENOUGH_ENTRIES',
                    ['entries' => count($entryIds), 'prizes' => $prizes->count()],
                );
            }

            $winners = [];

            /** @var array<int, int|string> $draw */
            $draw = RaffleDraw::assign(
                entryIds: $entryIds,
                places: $prizes->pluck('place')->all(),
            );

            foreach ($draw as $place => $entryId) {
                $prize = $prizes->firstWhere('place', (int) $place);
                $entry = RaffleEntry::query()->find($entryId);

                if ($prize === null || $entry === null) {
                    // Защита от несогласованных данных: место без приза или
                    // билет, исчезнувший между выборкой и вставкой. Такой
                    // тираж не проводится — пусть организатор повторит.
                    throw new DomainRuleViolation(
                        'Данные розыгрыша изменились во время тиража — повторите операцию.',
                        'RAFFLE_DATA_CHANGED',
                    );
                }

                try {
                    $winners[] = RaffleWinner::query()->create([
                        'raffle_id' => $locked->getKey(),
                        'raffle_prize_id' => $prize->getKey(),
                        'entry_id' => $entry->getKey(),
                        // Снимок победителя: аккаунт к моменту тиража мог
                        // исчезнуть, а результат переживает его.
                        'user_id' => $entry->user_id,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Кто-то провёл тираж одновременно: место занято или
                    // билет уже победил. Первый тираж состоялся — значит этот
                    // повтор, и молча «уже разыграно» честнее, чем сбой.
                    // Исключение-источник намеренно не цепляется: в ответе
                    // админке оно ничего не добавит, а в лог очередь его
                    // сложит и так.
                    //
                    // ПОЧЕМУ ЗДЕСЬ НЕ `createOrFirst()`, КАК У ОСТАЛЬНЫХ.
                    // У `raffle_winners` ДВА уникальных ключа —
                    // `uq_raffle_winners_prize (raffle_id, raffle_prize_id)` и
                    // `uq_raffle_winners_entry (raffle_id, entry_id)`.
                    // `createOrFirst()` переспрашивает по ОДНОМУ набору
                    // атрибутов; строка, отвергнутая вторым индексом, по
                    // первому набору не находится, и метод перевыбрасывает
                    // исключение — то есть 500 вместо внятного
                    // `RAFFLE_ALREADY_DRAWN`. Ни один набор из этих четырёх
                    // колонок не покрывает оба индекса, поэтому `catch`
                    // остаётся.
                    throw new DomainRuleViolation(
                        'Тираж уже проведён — результаты не перезаписаны.',
                        'RAFFLE_ALREADY_DRAWN',
                    );
                }
            }

            $locked->forceFill([
                'status' => Raffle::STATUS_DRAWN,
                'drawn_at' => now(),
            ])->save();

            NotifyRaffleWinnersJob::forRaffle($locked)
                ->onConnection('database')
                ->afterCommit();

            return collect($winners);
        });
    }

    // ───────────────────────────────────────────────────────────── чте́ние ──

    /** Найти розыгрыш по публичному идентификатору (26 символов). */
    public function findByPublicId(string $publicId): ?Raffle
    {
        return Raffle::query()->where('public_id', $publicId)->first();
    }

    /**
     * Розыгрыш с призами, победителями и заказами победителей — для страницы
     * результатов и ответа админки.
     */
    public function results(Raffle $raffle): Raffle
    {
        return $raffle->load(['orderedPrizes', 'winners.prize', 'winners.entry.order', 'winners.user']);
    }

    /**
     * Участия покупателя: розыгрыши, в которых у него есть билеты.
     *
     * Черновики и отменённые розыгрыши не показываются: первый ещё не
     * существует для покупателя, второй закончился ничьей, и участие в нём
     * выглядело бы обещанием, которого не будет.
     *
     * @return list<array<string, mixed>>
     */
    public function myRaffles(\Nabilet\Modules\Core\Users\Models\User $user): array
    {
        $raffleIds = RaffleEntry::query()
            ->forUser($user->getKey())
            ->pluck('raffle_id')
            ->unique()
            ->all();

        if ($raffleIds === []) {
            return [];
        }

        return Raffle::query()
            ->visible()
            ->whereKey($raffleIds)
            ->with('orderedPrizes')
            ->withCount('entries')
            ->get()
            ->map(function (Raffle $raffle) use ($user): array {
                $won = RaffleWinner::query()
                    ->where('raffle_id', $raffle->getKey())
                    ->where('user_id', $user->getKey())
                    ->with('prize')
                    ->get()
                    ->map(fn (RaffleWinner $winner): array => [
                        'place' => (int) ($winner->prize?->place ?? 0),
                        'prize_title' => (string) ($winner->prize?->title ?? ''),
                        'notified' => $winner->isNotified(),
                    ])
                    ->all();

                return [
                    'public_id' => $raffle->public_id,
                    'title' => $raffle->title,
                    'description' => $raffle->description,
                    'status' => $raffle->status,
                    'starts_at' => $raffle->starts_at,
                    'ends_at' => $raffle->ends_at,
                    'drawn_at' => $raffle->drawn_at,
                    'entries_count' => (int) $raffle->entries_count,
                    'prizes' => $raffle->orderedPrizes->map(fn (RafflePrize $prize): array => [
                        'place' => (int) $prize->place,
                        'title' => $prize->title,
                    ])->all(),
                    'won' => $won,
                ];
            })
            ->all();
    }

    /**
     * Победители, которым письмо ещё не уходило.
     *
     * Без аргумента — по всем розыгрышам (админская сводка «кому не
     * отправилось»); с розыгрышем — только его победители, что и нужно
     * `NotifyRaffleWinnersJob`: джоба на розыгрыш не должна трогать чужие.
     *
     * @return Collection<int, RaffleWinner>
     */
    public function winnersPendingNotification(?Raffle $raffle = null): Collection
    {
        return RaffleWinner::query()
            ->when($raffle !== null, fn ($query) => $query->where('raffle_id', $raffle->getKey()))
            ->pendingNotification()
            ->with(['prize', 'entry.order', 'raffle', 'user'])
            ->get();
    }

    // ──────────────────────────────────────────────────────────── служебное ──

    /**
     * Синхронизировать призы по месту.
     *
     * Обновление на месте, а не «удалить все и создать заново»: приз —
     * ссылка победителя (`raffle_winners.raffle_prize_id`), и пересоздание
     * сломало бы проведённый тираж, даже если места совпадают. Место без
     * приза удаляется — приз organisator убрал из формы.
     *
     * @param  list<array{place: int, title: string, description?: string|null}>  $prizes
     */
    private function syncPrizes(Raffle $raffle, array $prizes): void
    {
        $byPlace = [];

        foreach ($prizes as $prize) {
            $byPlace[(int) $prize['place']] = $prize;
        }

        /** @var Collection<int, RafflePrize> $existing */
        $existing = $raffle->prizes()->get()->keyBy('place');

        foreach ($byPlace as $place => $prize) {
            $current = $existing->get((int) $place);

            if ($current !== null) {
                $current->fill([
                    'title' => $prize['title'],
                    'description' => $prize['description'] ?? null,
                ])->save();

                continue;
            }

            RafflePrize::query()->create([
                'raffle_id' => $raffle->getKey(),
                'place' => (int) $place,
                'title' => $prize['title'],
                'description' => $prize['description'] ?? null,
            ]);
        }

        foreach ($existing as $place => $prize) {
            if (!array_key_exists((int) $place, $byPlace)) {
                $prize->delete();
            }
        }
    }

    /** Переход статуса — только по машине состояний, иначе исключение. */
    private function assertTransition(Raffle $raffle, string $to): void
    {
        if (!RaffleStateMachine::make()->can((string) $raffle->status, $to)) {
            throw new DomainRuleViolation(
                sprintf('Из статуса «%s» нельзя перейти в «%s».', $raffle->status, $to),
                'RAFFLE_INVALID_TRANSITION',
            );
        }
    }
}
