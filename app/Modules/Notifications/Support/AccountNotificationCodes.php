<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Support;

/**
 * Коды шаблонов писем, не связанных с заказом.
 *
 * Живут здесь, а не рядом с `OrderNotificationCodes`, потому что это разные
 * поводы: статус заказа пишется один раз на переход и уходит всем, письмо о
 * доступе к аккаунту инициирует сам пользователь. Совместить списки значило бы
 * связать частоту писем о заказах с восстановлением пароля.
 *
 * Значение — код шаблона (`notification_templates.code`) И одновременно `type`
 * в `notifications`, как и у заказов: одна строка списка, а не таблица
 * соответствий.
 */
final class AccountNotificationCodes
{
    /** Письмо со ссылкой на смену пароля. */
    public const PASSWORD_RESET = 'account.password_reset';

    /** Письмо со ссылкой подтверждения адреса. */
    public const EMAIL_VERIFICATION = 'account.email_verification';

    /**
     * Переменные, которые подставляет `TransactionalMailService`.
     *
     * Отдельный список, чтобы редактор шаблонов в админке не показывал
     * `order_number` в письме о смене пароля — иначе администратор подставит
     * плейсхолдер, который всегда рендерится пустым.
     *
     * @return list<string>
     */
    public static function variables(): array
    {
        return ['customer_name', 'action_url', 'expires_in_minutes'];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PASSWORD_RESET, self::EMAIL_VERIFICATION];
    }
}
