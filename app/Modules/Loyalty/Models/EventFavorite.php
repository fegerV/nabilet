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
 * Отметка «❤️» — мероприятие в личном списке «хочу посмотреть».
 *
 * ПОЧЕМУ ЭТО ОТДЕЛЬНАЯ ТАБЛИЦА, А НЕ ФЛАГ У `event_follows`
 *   Избранное не порождает писем, подписка порождает. Одна таблица с флагом
 *   заставила бы отвечать на вопрос «получает ли человек письма?» чтением
 *   флага, который никто не проверяет, и любая рассылка по «избранному»
 *   начала бы присылать письма тем, кто их не просил. Решение принято в схеме:
 *   `018_loyalty_foundation.sql`.
 *
 * `updated_at` нет в таблице намеренно: строка описывает факт «отметил в
 * момент T», а не состояние, которое меняется. Чтобы Eloquent не требовал
 * колонку на каждом UPDATE, `UPDATED_AT = null` — в этом случае он ведёт
 * только `created_at`.
 */
class EventFavorite extends Model
{
    protected $table = 'event_favorites';

    public $incrementing = true;

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
        static::creating(function (EventFavorite $favorite): void {
            if ($favorite->public_id === null) {
                $favorite->public_id = Str::ulid()->toBase32();
            }

            if ($favorite->created_at === null) {
                $favorite->created_at = now();
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
     * «Моё избранное» — список одного пользователя по времени добавления.
     *
     * @return Builder<EventFavorite>
     */
    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Повторное «❤️» — тот же факт, а не вторая строка и не ошибка.
     *
     * `firstOrCreate` здесь недостаточно: между его SELECT и INSERT влезает
     * второй запрос, и уникальный ключ `uq_event_favorites_user_event`
     * роняет вставку дубликата. Ловим именно нарушение ключа и возвращаем уже
     * существующую строку — повторное нажатие отвечает 200, а не 500.
     */
    public static function remember(int|string $userId, int|string $eventId): self
    {
        try {
            return self::create([
                'user_id' => $userId,
                'event_id' => $eventId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // NULL невозможен: ключ нарушился, значит строка есть — её только
            // что вставил кто-то другой, ровно между нашими SELECT и INSERT.
            // `firstOrFail`, а не `first()` + `assert()`: в продакшене
            // `assert()` выключен и молча вернул бы null из метода `: self`.
            return self::query()
                ->where('user_id', $userId)
                ->where('event_id', $eventId)
                ->firstOrFail();
        }
    }
}
