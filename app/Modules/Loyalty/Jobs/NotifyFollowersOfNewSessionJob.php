<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Nabilet\Modules\Loyalty\Models\EventFollow;
use Nabilet\Modules\Loyalty\Models\FollowNotification;
use Nabilet\Modules\Loyalty\Services\FollowNotificationData;
use Nabilet\Modules\Loyalty\Support\FollowNotificationCodes;
use Nabilet\Modules\Notifications\Services\TransactionalMailService;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Sessions\StateMachines\SessionStateMachine;
use Throwable;

/**
 * Письмо подписчикам о сеансе: новый сеанс или открытие продаж.
 *
 * ПОЧЕМУ ОДНА ДЖОБА НА СЕАНС, А НЕ НА ПОДПИСЧИКА
 *   Повод один на всех («сеанс появился»), и рассылка по нему — это fan-out.
 *   Джоба на подписчика означала бы N джоб в очереди на каждую новую дату:
 *   при живой аудитории это тысячи строк в `jobs` от одного клика организатора,
 *   и сбой очереди останавливал бы всю рассылку сразу, а не одно письмо.
 *
 * ПОЧЕМУ ЖУРНАЛ, А НЕ ПРОСТО `Mail::to()`
 *   Джоба повторяется (SMTP падает, воркер убит по `--max-time`), и повтор не
 *   должен означать второе письмо. Разрешение на отправку выдаёт
 *   `FollowNotification::claim()` — атомарная вставка под уникальным ключом
 *   `uq_follow_notifications_dedupe`. Поэтому повторный проход безопасен: то,
 *   что уже ушло, пропускается, а сорванное — забирается обратно.
 *
 * ПОЧЕМУ ПОВТОР ВОЗВРАЩАЕТ ИСКЛЮЧЕНИЕ
 *   Сбой транспорта отмечается в журнале (`markFailed`) и пробрасывается
 *   дальше: `TransactionalMailService` уже записала ошибку в `notifications`, а
 *   Laravel повторяет джобу по `tries`/`backoff`. Следующая попытка забирает
 *   `failed`-строку обратно (см. `claim()`), и письмо уходит. Если бы джоба
 *   глотала исключение, одно падение SMTP навсегда оставило бы подписчика без
 *   письма, а в очереди не осталось бы и следа.
 */
class NotifyFollowersOfNewSessionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Ошибка SMTP повторяется независимо от того, чем занят организатор. */
    public int $tries = 3;

    /** Несколько минут на восстановление SMTP, после — ошибка видна в очереди. */
    public array $backoff = [60, 300];

    /**
     * Сколько подписчиков берём за один запрос. Ограничение памяти, а не
     * логики: мероприятие с тысячами подписчиков не должно вычитывать их всех
     * в память одним `get()`.
     */
    private const CHUNK = 100;

    public function __construct(
        public readonly int $sessionId,
        public readonly string $templateCode = FollowNotificationCodes::NEW_SESSION,
    ) {}

    /**
     * Поставить письмо в очередь.
     *
     * Возвращается `PendingDispatch`, а не джоба: вызывающий навешивает
     * `onConnection()`/`afterCommit()` на ПОСТАНОВКУ, а не на объект джобы.
     * Вернуть отсюда `new self(...)` значило бы получить джобу, которую никто
     * не поставил в очередь — вызов `onConnection()` у самого объекта джобы
     * выглядит работающим, но до отправки дело не доходит.
     */
    public static function forSession(Session $session, string $templateCode): PendingDispatch
    {
        return self::dispatch(
            sessionId: (int) $session->id,
            templateCode: $templateCode,
        );
    }

    public function handle(TransactionalMailService $mail, FollowNotificationData $data): void
    {
        // Код приходит из обсервера, но джоба — публичная точка входа: чужой
        // код не должен превращаться в письмо с неизвестным шаблоном.
        if (! in_array($this->templateCode, FollowNotificationCodes::all(), true)) {
            return;
        }

        $session = Session::query()->with('event', 'venue')->find($this->sessionId);

        // Сеанс удалён — каскад унёс и журнал, рассылать нечего. Мероприятие
        // скрыто (`SoftDeletes`) — отношение вернёт null, и это тот же случай:
        // о снятом с показа мероприятии не пишем.
        if ($session === null || $session->event === null) {
            return;
        }

        // Отменённого сеанса не будет, завершённый уже прошёл: письмо по любому
        // из них звало бы человека зря. `closed` остаётся — продажи закрыты, но
        // выступление впереди, и «не пропусти» именно про него.
        if (in_array($session->status, [SessionStateMachine::CANCELLED, SessionStateMachine::COMPLETED], true)) {
            return;
        }

        // Для «открылись продажи» важен момент отправки: между постановкой в
        // очередь и отправкой продажи могли закрыть, и письмо «покупайте»
        // вело бы людей на закрытую кассу. Для «новый сеанс» такой проверки
        // нет — факт появления даты не отменяется закрытыми продажами.
        if ($this->templateCode === FollowNotificationCodes::SALES_OPEN && ! $session->isSellableAt()) {
            return;
        }

        EventFollow::query()
            ->forEvent($session->event_id)
            ->chunkById(self::CHUNK, function ($follows) use ($mail, $data, $session): void {
                foreach ($follows as $follow) {
                    $this->notify($mail, $data, $session, $follow);
                }
            });
    }

    private function notify(
        TransactionalMailService $mail,
        FollowNotificationData $data,
        Session $session,
        EventFollow $follow,
    ): void {
        // Право на письмо. `null` — по этой тройке (подписчик, сеанс, повод)
        // уже рассылали, либо письмо уже ушло: второй раз не отправляем никогда.
        $journal = FollowNotification::claim(
            followId: $follow->id,
            userId: (int) $follow->user_id,
            eventId: (int) $session->event_id,
            sessionId: $session->id,
            templateCode: $this->templateCode,
        );

        if ($journal === null) {
            return;
        }

        $user = $follow->user;

        if ($user === null || trim((string) $user->email) === '') {
            // Адресата нет. Запись остаётся `failed` с причиной: подписку это не
            // отменяет (человек нажмёт «🔔» снова, указав почту), но в разборе
            // видно, почему письма не было.
            $journal->markFailed('У подписчика нет адреса электронной почты.');

            return;
        }

        try {
            $log = $mail->send(
                code: $this->templateCode,
                recipient: (string) $user->email,
                variables: $data->build($follow, $session),
                userId: (int) $user->id,
            );

            if ($log === null) {
                // Шаблон выключен или не создан: это решение администратора, а
                // не сбой транспорта. Повторять такое письмо по `backoff` надо
                // не до успеха, а до включения шаблона — поэтому исключения нет.
                $journal->markFailed('Шаблон письма не найден или выключен.');

                return;
            }

            $journal->markSent($log->id);
        } catch (Throwable $e) {
            $journal->markFailed($e->getMessage());

            throw $e;
        }
    }
}
