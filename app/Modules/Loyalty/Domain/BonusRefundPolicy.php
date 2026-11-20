<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Domain;

/**
 * Сколько бонусов вернуть при частичном возврате денег.
 *
 * Чистое правило без БД и без фреймворка: его можно проверить юнит-тестом на
 * границах (один билет из десяти, последний билет, повтор того же возврата).
 *
 * Возврат пропорционален УЖЕ ВОЗВРАЩЁННОЙ сумме (а не этой отдельной операции):
 * несколько частичных возвратов последовательно сходятся к полному без переплаты.
 * Округление вниз в пользу организатора; последний возврат добирает остаток,
 * потому что целевая сумма ограничена полным списанием.
 */
final class BonusRefundPolicy
{
    /**
     * @param  int  $spent  сколько бонусов было списано за заказ
     * @param  int  $total  полная сумма заказа в минорных единицах
     * @param  int  $refunded  накопленная возвращённая сумма денег
     * @param  int  $alreadyReturned  бонусы, уже возвращённые по этому заказу
     * @return int сколько ещё вернуть (>= 0, не больше остатка)
     */
    public static function portion(int $spent, int $total, int $refunded, int $alreadyReturned): int
    {
        if ($spent <= 0 || $total <= 0 || $refunded <= 0) {
            return 0;
        }

        $target = intdiv($spent * min($refunded, $total), $total);
        $target = max(0, min($spent, $target));

        return max(0, $target - max(0, $alreadyReturned));
    }
}
