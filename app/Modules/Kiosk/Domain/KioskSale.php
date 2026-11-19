<?php

declare(strict_types=1);

namespace Nabilet\Modules\Kiosk\Domain;

/**
 * Статусы продажи киоска и правила переходов.
 *
 * Ключевое отличие от web-чекаута: продажа создаётся ДО ответа сервера и
 * живёт с client_sale_id, который устройство сгенерировало само. Повторный
 * запрос с тем же id возвращает ту же строку — это и есть защита от двойного
 * нажатия «Продать» при обрыве сети.
 *
 * QR становится доступен только в `issued`: подпись создаётся на сервере
 * после webhook-а об оплате, киоск никогда не подписывает билет сам.
 */
final class KioskSale
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_PAID,
        self::STATUS_ISSUED,
        self::STATUS_FAILED,
        self::STATUS_REFUNDED,
    ];

    private const ALLOWED = [
        self::STATUS_PENDING => [self::STATUS_AWAITING_PAYMENT, self::STATUS_FAILED],
        self::STATUS_AWAITING_PAYMENT => [self::STATUS_PAID, self::STATUS_FAILED],
        self::STATUS_PAID => [self::STATUS_ISSUED, self::STATUS_REFUNDED],
        self::STATUS_ISSUED => [self::STATUS_REFUNDED],
        self::STATUS_FAILED => [],
        self::STATUS_REFUNDED => [],
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public static function isTerminal(string $status): bool
    {
        return (self::ALLOWED[$status] ?? []) === [];
    }
}
