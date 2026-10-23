<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Domain;

/**
 * Состояния корзины — словарь из схемы, а не из литералов в 16 местах.
 *
 * ЗАЧЕМ. `carts.status` писался и читался голой строкой в шестнадцати местах
 * боевого контура: `'active'` одиннадцать раз, `'converted'` и `'abandoned'`
 * ещё по три. Владелец словаря существовал — но не здесь.
 * `Nabilet\Modules\Orders\Domain\Superseded\CartState` объявляет себя «единственным
 * домом» этой колонки и при этом содержит ЧЕТЫРЕ значения, включая `expired`,
 * которого `ck_carts_status` не допускает. Архивный кластер намеренно вынесен
 * из боевого пути (`docs/CODE-QUALITY-GUIDE.md` §9.9), поэтому его словарь
 * боевой код не видел — и писал свои копии.
 *
 * ПОЧЕМУ ИМЕННО ТРИ ЗНАЧЕНИЯ. `ck_carts_status` (spec, §«carts») допускает
 * `active`, `converted`, `abandoned`. `expired` из архивной модели в колонку
 * не записать: MySQL ответит отказом, то есть 500 вместо осмысленной ошибки.
 * Словарь здесь — ровно то, что позволяет схема, и ни одним значением больше.
 *
 * ГЛАВНОЕ ПРАВИЛО. Только `active` разрешает запись и оформление. Это защита
 * от самого дорогого бага в этом потоке: повторная оплата (дабл-клик,
 * повторный запрос, переигранный идемпотентный POST) создаёт ВТОРОЙ заказ из
 * той же корзины и продаёт те же места дважды. `converted` — корзина уже
 * стала заказом; `abandoned` — от неё ушли, и воскрешать её с устаревшими
 * ценами нельзя. Обе терминальны.
 *
 * СОГЛАСОВАНО С `docs/STATE-MACHINES.md` и с `ck_carts_status`; гейт
 * `tools/verify-state-machines.php` сверяет машины с CHECK-ограничениями, но
 * этот класс машиной не является — он словарь, и проверять его соответствие
 * схеме надо здесь, при каждом изменении.
 */
final class CartStatus
{
    /** Живая корзина: единственное состояние, в котором можно писать и платить. */
    public const ACTIVE = 'active';

    /** Превращена в заказ. История: переиспользовать нельзя. */
    public const CONVERTED = 'converted';

    /** Брошена покупателем. Терминальное состояние, цены в ней устарели. */
    public const ABANDONED = 'abandoned';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ACTIVE, self::CONVERTED, self::ABANDONED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * Можно ли писать в корзину и оформлять из неё заказ.
     *
     * Fail-closed: незнакомый статус — не `active`. Так опечатка или новое
     * состояние, про которое здесь забыли, закрывают доступ, а не открывают.
     */
    public static function acceptsChanges(string $status): bool
    {
        return $status === self::ACTIVE;
    }

    /** Синоним `acceptsChanges()` для читаемости на пути оформления заказа. */
    public static function canCheckOut(string $status): bool
    {
        return self::acceptsChanges($status);
    }
}
