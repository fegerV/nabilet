<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Models;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nabilet\Modules\Loyalty\Domain\RaffleWindow;
use Nabilet\Modules\Loyalty\StateMachines\RaffleStateMachine;

/**
 * Розыгрыш «счастливый билет» (фаза 5 `docs/LOYALTY-PLAN.md`).
 *
 * СТАТУС — ЖИЗНЕННЫЙ ЦИКЛ, А НЕ НАБОР ФЛАГОВ
 *   `draft` (готовится) → `active` (набирает участников) → `drawn` (тираж
 *   проведён); `cancelled` — закрыт без тиража. Словарь закрыт CHECK
 *   `ck_raffles_status`, переходы описаны `RaffleStateMachine`. Отдельного
 *   флага «тираж проведён» нет намеренно: `drawn_at` уточняет МОМЕНТ, а
 *   наличие тиража говорит статус. Флаг и статус разошлись бы при первой же
 *   частичной записи — тот же урок, что `bonus_paid_at` в фазе 4.
 *
 * ОКНО — ДВЕ NULLABLE-ДАТЫ
 *   Заказы, оплаченные с `starts_at` по `ends_at` (включительно), становятся
 *   билетами. NULL — «без ограничения». Проверка окна живёт в чистом
 *   `Domain\RaffleWindow`: её делают и обсервер оплаты, и backfill
 *   активации, и разойтись они не имеют права.
 *
 * УЧАСТНИКИ ПОРОЖДАЮТСЯ ОПЛАТОЙ, А НЕ ТИРАЖОМ
 *   `raffle_entries` создаёт `OrderRaffleObserver` (и backfill при
 *   активации), поэтому розыгрыш за прошлый период наполняется в момент
 *   включения и не требует ручного импорта заказов.
 */
class Raffle extends Model
{
    use HasPublicId;

    public const STATUS_DRAFT = RaffleStateMachine::DRAFT;

    public const STATUS_ACTIVE = RaffleStateMachine::ACTIVE;

    public const STATUS_DRAWN = RaffleStateMachine::DRAWN;

    public const STATUS_CANCELLED = RaffleStateMachine::CANCELLED;

    protected $table = 'raffles';

    protected $fillable = [
        'public_id',
        'title',
        'description',
        'status',
        'starts_at',
        'ends_at',
        'drawn_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime:Y-m-d H:i:s.u',
            'ends_at' => 'datetime:Y-m-d H:i:s.u',
            'drawn_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    /** @return HasMany<RafflePrize, $this> */
    public function prizes(): HasMany
    {
        return $this->hasMany(RafflePrize::class, 'raffle_id');
    }

    /** @return HasMany<RaffleEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(RaffleEntry::class, 'raffle_id');
    }

    /** @return HasMany<RaffleWinner, $this> */
    public function winners(): HasMany
    {
        return $this->hasMany(RaffleWinner::class, 'raffle_id');
    }

    /**
     * Призы по порядку мест: 1-е, 2-е, 3-е.
     *
     * Сортировка по `place`, а не по `id`: порядок вставки призов — деталь
     * формы, а место — смысл приза.
     *
     * @return HasMany<RafflePrize, $this>
     */
    public function orderedPrizes(): HasMany
    {
        return $this->prizes()->orderBy('place');
    }

    /**
     * Окно розыгрыша как чистое значение — для `RaffleWindow::covers()`.
     */
    public function window(): RaffleWindow
    {
        return new RaffleWindow(
            status: (string) $this->status,
            startsAt: $this->starts_at,
            endsAt: $this->ends_at,
        );
    }

    /** Входит ли оплата в момент `$moment` в окно розыгрыша. */
    public function covers(?\DateTimeInterface $moment = null): bool
    {
        return $this->window()->covers($moment);
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, [self::STATUS_DRAWN, self::STATUS_CANCELLED], true);
    }

    /** @return Builder<Raffle> */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('status', RaffleStateMachine::visible());
    }
}
