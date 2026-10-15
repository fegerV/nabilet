<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Events\Models\Event;

/**
 * Подписка «🔔» — просьба присылать письма о мероприятии.
 *
 * ПОЧЕМУ ЭТО ОТДЕЛЬНАЯ ТАБЛИЦА, А НЕ ФЛАГ У `event_favorites`
 *   Избранное — личный список «хочу посмотреть» и писем не порождает. Подписка
 *   порождает. Слить их в одну таблицу с флагом значило бы отвечать на вопрос
 *   «получает ли человек письма?» чтением флага, который никто не проверяет.
 *   Решение принято в схеме: `018_loyalty_foundation.sql`.
 *
 * `followersOfEvent` — единственный запрос, которым пользуется рассылка о
 * новом сеансе (`docs/LOYALTY-PLAN.md`, фаза 1): «кому писать по этому
 * мероприятию». Он же объясняет индекс `idx_event_follows_event`.
 */
class EventFollow extends Model
{
    protected $table = 'event_follows';

    protected $fillable = [
        'public_id',
        'user_id',
        'event_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (EventFollow $follow): void {
            if ($follow->public_id === null) {
                $follow->public_id = Str::ulid()->toBase32();
            }

            if ($follow->created_at === null) {
                $follow->created_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Подписки одного пользователя, свежие сверху.
     *
     * @return Builder<EventFollow>
     */
    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Кому писать о мероприятии.
     *
     * Пользователь подгружается сразу: рассылка идёт по адресу подписчика, и
     * без `with('user')` она сделала бы по одному запросу на подписчика — то
     * есть ровно ту N+1, которую заметят на первом же мероприятии с живой
     * аудиторией.
     *
     * @return Builder<EventFollow>
     */
    public function scopeForEvent(Builder $query, int|string $eventId): Builder
    {
        return $query->where('event_id', $eventId)->with('user');
    }

    /**
     * Повторное «🔔» — тот же факт, а не вторая подписка и не ошибка.
     *
     * Та же защита от гонки, что у `EventFavorite::remember()`: между SELECT и
     * INSERT влезает второй запрос, и `uq_event_follows_user_event` роняет
     * вставку дубликата.
     */
    public static function subscribe(int|string $userId, int|string $eventId): self
    {
        try {
            return self::create([
                'user_id' => $userId,
                'event_id' => $eventId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return self::query()
                ->where('user_id', $userId)
                ->where('event_id', $eventId)
                ->firstOrFail();
        }
    }
}
