<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Domain;

/**
 * Причины движения по бонусному счёту — и ничего кроме них.
 *
 * ЗАЧЕМ КЛАСС, А НЕ СТРОКИ В ВЫЗОВАХ
 *   Та же причина, по которой существуют `LoginLogEvent` и `UserStatus`: список,
 *   написанный строкой в каждом вызывающем, — это список, который разойдётся.
 *   Здесь он один, и он же процитирован в `ck_bonus_transactions_reason`
 *   (сплит `019_bonus_ledger.sql`). `BonusPolicyTest` сверяет эти два списка
 *   между собой, поэтому причина, добавленная здесь без правки схемы, краснит
 *   гейт, а не падает на CHECK у покупателя.
 *
 * СЛОВАРЬ ОБЪЯВЛЕН НА ВСЮ ФАЗУ 2, А НЕ НА ТО, ЧТО ПИШЕТСЯ СЕГОДНЯ
 *   Сейчас пишется только `ACCRUAL` (фаза 2a). Остальные причины — правила,
 *   уже принятые в `docs/LOYALTY-PLAN.md` (подарки за регистрацию и первую
 *   покупку, списание в чекауте, компенсация при возврате, истечение через 12
 *   месяцев). Расширение словаря — это правка CHECK, то есть миграция, и план
 *   предостерегает от неё отдельно; объявить словарь целиком дешевле, чем
 *   проходить этот путь ради каждого нового писателя.
 */
final class BonusReason
{
    /** Кэшбэк 5% от оплаченного заказа. Единственная причина, которую пишет фаза 2a. */
    public const ACCRUAL = 'accrual';

    /** Подарок за регистрацию (+100 бонусов по таблице правил плана). */
    public const REGISTRATION = 'registration';

    /** Подарок за первую покупку (+300). */
    public const FIRST_PURCHASE = 'first_purchase';

    /** Списание бонусов в чекауте (фаза 2b). */
    public const SPEND = 'spend';

    /**
     * Компенсация: возврат или отмена. Направление несёт знак `delta`, а не
     * причина — одна и та же причина закрывает и откат списания (плюс), и
     * отзыв кэшбэка за возвращённый заказ (минус).
     */
    public const REFUND = 'refund';

    /** Истечение срока: 12 месяцев с момента начисления. */
    public const EXPIRY = 'expiry';

    /**
     * Словарь целиком, в порядке объявления.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ACCRUAL,
            self::REGISTRATION,
            self::FIRST_PURCHASE,
            self::SPEND,
            self::REFUND,
            self::EXPIRY,
        ];
    }

    public static function isKnown(string $reason): bool
    {
        return in_array($reason, self::all(), true);
    }
}
