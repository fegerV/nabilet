<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Domain;

/**
 * Статусы доставки уведомления — словарь из схемы, а не из литералов в трёх местах.
 *
 * ЗАЧЕМ. `notifications.status` — колонка журнала отправки. Её словарь
 * (`queued`, `sent`, `failed`) был выписан трижды, и каждый раз заново:
 *
 *  1. `Notifications\Services\AccountMailService` — три константы `STATUS_*`;
 *  2. `Notifications\Services\TransactionalMailService` — те же три, байт в байт;
 *  3. `Loyalty\Models\FollowNotification` — снова те же три, для таблицы
 *     `follow_notifications`, у которой словарь совпадает (`ck_follow_notifications_status`).
 *
 * Три копии одного правила — это три места, которые надо поправить, когда
 * правило меняется, и три места, где оно может недопоправиться. Владельца не
 * было вовсе: модель `Notification` словаря не объявляет, а `AccountMailService`
 * и `TransactionalMailService` — независимые классы, ни один не знает о другом.
 *
 * ПОЧЕМУ ИМЕННО ТРИ ЗНАЧЕНИЯ. `ck_notifications_status` (spec, сплит 017)
 * допускает `queued`, `sent`, `failed` и больше ничего. Ни одно значение здесь
 * не придумано: `queued` — дефолт колонки в схеме, `sent` и `failed` писали
 * оба транспортных сервиса ещё до того, как словарь получил имя.
 *
 * ГЛАВНОЕ ПРАВИЛО. Запись живёт, пока транспорт не подтвердил отправку:
 * `queued` — единственное нетерминальное состояние, из неё запись уходит
 * либо в `sent`, либо в `failed`. Повторная отправка берёт строки в `failed`
 * (транспорт сказал «нет» — повтор джобы его догонит) и «сорванные» `queued`,
 * которые не двигались дольше аренды воркера. Смысл этих двух правил теперь
 * выражен здесь, а не восстанавливается по чтению `FollowNotification::takeOver()`.
 *
 * СОГЛАСОВАНО С `ck_notifications_status` и `ck_follow_notifications_status`;
 * гейт `tools/verify-status-literals.php` сверяет места записи с этим классом.
 * Машиной состояний этот класс не является: переходов между тремя значениями
 * не описывает никто, кроме двух сервисов, и описывать их здесь значило бы
 * выдумать соглашение.
 */
final class NotificationStatus
{
    /**
     * Запись создана, транспорт ещё не подтвердил отправку.
     *
     * Дефолт колонки в схеме — строка, созданная до появления владельца,
     * уже в этом состоянии.
     */
    public const QUEUED = 'queued';

    /** Транспорт подтвердил отправку. Терминально. */
    public const SENT = 'sent';

    /** Транспорт отказал. Терминально, но повторяемо: повтор джобы догонит. */
    public const FAILED = 'failed';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::QUEUED, self::SENT, self::FAILED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * Дошло ли письмо (подтверждено транспортом).
     *
     * Fail-closed: незнакомый статус — «не дошло». Опечатка или состояние,
     * про которое здесь забыли, не должны выглядеть как успешная доставка.
     */
    public static function isDelivered(string $status): bool
    {
        return $status === self::SENT;
    }

    /**
     * Терминально ли состояние.
     *
     * `failed` терминален для ЭТОЙ попытки, но не для записи: повторная
     * отправка переписывает строку в `queued`, а не создаёт новую. Поэтому
     * терминальность здесь отвечает на вопрос «нужен ли ещё транспорт»,
     * а не «может ли строка измениться».
     */
    public static function isTerminal(string $status): bool
    {
        return $status === self::SENT || $status === self::FAILED;
    }
}
