<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Победитель призового места — результат тиража (фаза 5).
 *
 * РЕЗУЛЬТАТ, А НЕ УЧАСТИЕ
 *   Строка создаётся тиражом (одна транзакция на все места) и после этого не
 *   правится — `updated_at` нет по той же причине, что у `raffle_entries`.
 *   Единственное движение — `notified_at`: письмо победителю ушло. Это
 *   идемпотентность уведомления и признак «можно отправить повторно» в
 *   админке; сами призы и места не переписываются никогда.
 *
 * СНИМОК, А НЕ ССЫЛКА НА ЖИВОГО ПОЛЬЗОВАТЕЛЯ
 *   `user_id` — кто выиграл НА МОМЕНТ ТИРАЖА (SET NULL при удалении
 *   аккаунта). Письмо победителю адресуется по адресу заказа
 *   (`entry->order->customer_email`), а не по аккаунту: у guest-победителя
 *   аккаунта может не быть вовсе, и результат тиража не должен от этого
 *   зависеть.
 *
 * ИНВАРИАНТЫ СХЕМЫ
 *   `UNIQUE (raffle_id, raffle_prize_id)` — на место один победитель;
 *   `UNIQUE (raffle_id, entry_id)` — один билет не забирает двух мест
 *   (тираж идёт без возврата, `Domain\RaffleDraw`). Повторный тираж,
 *   проскочивший мимо блокировки сервиса, упрётся в эти ключи — поэтому
 *   конфликт вставки сервис трактует как `RAFFLE_ALREADY_DRAWN`, а не как
 *   сбой.
 */
class RaffleWinner extends Model
{
    /**
     * В таблице только `created_at`: результат тиража не переписывается.
     *
     * Единственная правка победителя — `notified_at`, и она идёт через
     * `forceFill()`/`save()`, которым `updated_at` не нужен.
     * `UPDATED_AT = null` отключает его обслуживание, оставляя `created_at`
     * автоматическим.
     */
    public const UPDATED_AT = null;

    protected $table = 'raffle_winners';

    protected $fillable = [
        'public_id',
        'raffle_id',
        'raffle_prize_id',
        'entry_id',
        'user_id',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RaffleWinner $winner): void {
            if ($winner->public_id === null) {
                $winner->public_id = Str::ulid()->toBase32();
            }
        });
    }

    /** @return BelongsTo<Raffle, $this> */
    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class, 'raffle_id');
    }

    /** @return BelongsTo<RafflePrize, $this> */
    public function prize(): BelongsTo
    {
        return $this->belongsTo(RafflePrize::class, 'raffle_prize_id');
    }

    /** @return BelongsTo<RaffleEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(RaffleEntry::class, 'entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isNotified(): bool
    {
        return $this->notified_at !== null;
    }

    /**
     * Отметить письмо отправленным.
     *
     * `forceFill` + `saveQuietly` не нужны: наблюдателей на модели нет, а
     * `notified_at` — единственная колонка, которой ведает джоба уведомлений.
     */
    public function markNotified(): void
    {
        if ($this->notified_at === null) {
            $this->forceFill(['notified_at' => now()])->save();
        }
    }

    /** @return Builder<RaffleWinner> */
    public function scopePendingNotification(Builder $query): Builder
    {
        return $query->whereNull('notified_at');
    }
}
