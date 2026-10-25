<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Models;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Nabilet\Modules\Notifications\Domain\NotificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Журнал подписных писем — идемпотентность отправки в схеме, а не в коде.
 *
 * ЗАЧЕМ ОН
 *   Сеансы у мероприятия появляются регулярно, и одно письмо не должно уйти
 *   дважды. Уникальный ключ `uq_follow_notifications_dedupe`
 *   (follow_id, session_id, template_code) превращает «отправить повторно» в
 *   конфликт вставки: `claim()` либо создаёт строку и возвращает её, либо
 *   получает `null` — «по этой тройке уже рассылали».
 *
 *   Проверка «уже есть?» отдельным SELECT недостаточно: между ней и вставкой
 *   влезает второй процесс (крон и ручной запуск, два тика внахлёст). Это та
 *   же защита, что `uq_order_reminders_order` у напоминаний — там она
 *   описана подробнее.
 *
 * `sent_at` остаётся NULL, пока транспорт не подтвердил отправку:
 * «запланировано, но не подтверждено» видно в разборе и не гонит свип в круг.
 */
class FollowNotification extends Model
{
    use HasPublicId;

    /**
     * Словарь `follow_notifications.status` (`ck_follow_notifications_status`).
     *
     * ПСЕВДОНИМЫ, А НЕ ЗНАЧЕНИЯ. Этот словарь совпадает с `notifications.status`
     * (`ck_notifications_status`), и до появления `NotificationStatus` он был
     * выписан здесь третий раз — рядом с двумя копиями в транспортных сервисах.
     * Теперь значение живёт в одном классе, а здесь осталась только удобная
     * для вызывающих форма: тесты и `claim()`/`markSent()`/`markFailed()`
     * обращаются к статусу через модель, чью колонку они правят.
     *
     * Менять значения ЗДЕСЬ нельзя — менять надо `NotificationStatus`, иначе
     * три копии разъедутся снова, ровно как до этой правки.
     */
    public const STATUS_QUEUED = NotificationStatus::QUEUED;

    public const STATUS_SENT = NotificationStatus::SENT;

    public const STATUS_FAILED = NotificationStatus::FAILED;

    protected $table = 'follow_notifications';

    protected $fillable = [
        'public_id',
        'follow_id',
        'user_id',
        'event_id',
        'session_id',
        'template_code',
        'status',
        'notification_id',
        'sent_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function follow(): BelongsTo
    {
        return $this->belongsTo(EventFollow::class, 'follow_id');
    }

    public function notification(): BelongsTo
    {
        // Журнал транспорта живёт в `Nabilet\Modules\Notifications\Models\Notification` (модуль
        // Notifications): запись в нём — это «письмо ушло в провайдера», а не
        // факт разрешения на отправку, который фиксирует эта таблица.
        return $this->belongsTo(\Nabilet\Modules\Notifications\Models\Notification::class, 'notification_id');
    }

    /**
     * Захватить право на письмо. `null` — по этой тройке уже рассылали.
     *
     * Возвращает именно захваченную строку, а не `bool`: вызывающему нужны её
     * `id` (чтобы записать `notification_id`) и `public_id` (для разбора), и
     * второй SELECT после успешной вставки был бы лишним запросом.
     *
     * ДВА ПУТИ, ПОТОМУ ЧТО ПОВТОР БЫВАЕТ РАЗНЫЙ
     *   1. Вставка под unique-ключом. Успех — строка наша, статус `queued`.
     *   2. Конфликт — строка уже есть, и дальше решает `takeOver()`: письмо
     *      ушло (`sent`) — никто и никогда; сбой транспорта (`failed`) —
     *      забираем и отправляем заново; сорванная попытка (`queued`, который
     *      не двигался дольше аренды) — тоже забираем, потому что ждать её
     *      некому.
     *
     * Аренда, а не «любой `queued» наш»: два `updated()` подряд (включили
     * `on_sale`, тут же поправили окно) ставят две джобы, и вторая без аренды
     * отправила бы письмо повторно — ровно ту жалобу «письмо пришло дважды»,
     * для разбора которой существует `scopeForSession`.
     */
    public static function claim(
        int|string $followId,
        int|string $userId,
        int|string $eventId,
        int|string $sessionId,
        string $templateCode,
    ): ?self {
        // Гонку закрывает `uq_follow_notifications_dedupe (follow_id, session_id,
        // template_code)`, и обрабатывает её `createOrFirst()`, а не свой
        // `try/catch`: он ловит нарушение ключа, переспрашивает по тем же
        // атрибутам и возвращает существующую строку, обернув вставку в
        // `withSavepointIfNeeded()`.
        $notification = self::query()->createOrFirst(
            [
                'follow_id' => $followId,
                'session_id' => $sessionId,
                'template_code' => $templateCode,
            ],
            [
                'user_id' => $userId,
                'event_id' => $eventId,
                'status' => self::STATUS_QUEUED,
            ],
        );

        // Путь 1: вставка удалась — строка наша, статус `queued`.
        if ($notification->wasRecentlyCreated) {
            return $notification;
        }

        // Путь 2: строка уже есть, и дальше решает `takeOver()`.
        return self::takeOver($followId, $sessionId, $templateCode);
    }

    /**
     * Забрать существующую строку, если её владелец мёртв.
     *
     * UPDATE с условием в WHERE, а не SELECT и потом решение: условие проверяет
     * база, поэтому два процесса не могут «забрать» одну строку — UPDATE
     * затрагивает её ровно один раз. SELECT-then-decide этой гарантии не даёт:
     * оба прочитают `failed` и обе отправят письмо.
     *
     * Порог — `retry_after` очереди (`NABILET_HOLD_TTL` + 60с, см.
     * `config/queue.php`): убитый воркер отдаёт джобу обратно только через
     * него, так что к моменту повтора возраст строки заведомо больше аренды.
     */
    private const LEASE_MINUTES = 5;

    private static function takeOver(
        int|string $followId,
        int|string $sessionId,
        string $templateCode,
    ): ?self {
        $taken = self::query()
            ->where('follow_id', $followId)
            ->where('session_id', $sessionId)
            ->where('template_code', $templateCode)
            ->where(function (Builder $query): void {
                $query
                    // Транспорт сказал «нет»: письмо не ушло, и повтор джобы —
                    // тот самый механизм, который его догонит.
                    ->where('status', self::STATUS_FAILED)
                    // Сорванная попытка: `queued` без движения дольше аренды.
                    ->orWhere(function (Builder $stale): void {
                        $stale->where('status', self::STATUS_QUEUED)
                            ->where('updated_at', '<=', now()->subMinutes(self::LEASE_MINUTES));
                    });
            })
            ->update(['status' => self::STATUS_QUEUED, 'updated_at' => now()]);

        if ($taken === 0) {
            return null;
        }

        return self::query()
            ->where('follow_id', $followId)
            ->where('session_id', $sessionId)
            ->where('template_code', $templateCode)
            ->first();
    }

    public function markSent(int|string $notificationId): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'notification_id' => $notificationId,
            'sent_at' => now(),
            'error_message' => null,
        ])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => mb_substr($message, 0, 1000),
        ])->save();
    }

    /**
     * Что уже ушло по этому сеансу — разбор жалоб «письмо пришло дважды».
     *
     * @return Builder<FollowNotification>
     */
    public function scopeForSession(Builder $query, int|string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }
}
