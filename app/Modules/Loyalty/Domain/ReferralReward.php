<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Domain;

/**
 * Награда за приглашение: кому и сколько.
 *
 * ЗАЧЕМ ОБЪЕКТ, А НЕ МАССИВ
 *   Решение «платим ли пригласившему, платим ли другу, по сколько каждому»
 *   принимается один раз на оплаченный заказ, и дальше по нему пишутся ДВЕ
 *   строки журнала. Массив `['inviter' => ..., 'invited' => ...]` позволил бы
 *   вызвавшему прочитать несуществующий ключ или перепутать порядок; объект
 *   с методами `paysInviter()`/`paysInvited()` делает ошибку немыслимой.
 *
 * ПОЧЕМУ НУЛЬ — ЭТО НЕ «ВЫКЛЮЧЕНО»
 *   Настройка «бонус другу = 0» выключает только половину награды: пригласивший
 *   всё равно получает свою. Поэтому `enabled` отдельно от сумм — выключенная
 *   программа не платит никому, программа с нулём для друга платит одному.
 */
final class ReferralReward
{
    public function __construct(
        public readonly bool $enabled,
        /** Минорные единицы (копейки), как и все бонусы схемы. */
        public readonly int $inviterAmount,
        public readonly int $invitedAmount,
    ) {
    }

    public function paysInviter(): bool
    {
        return $this->enabled && $this->inviterAmount > 0;
    }

    public function paysInvited(): bool
    {
        return $this->enabled && $this->invitedAmount > 0;
    }

    /** Награда ничего не означает: писать строки журнала не нужно. */
    public function isZero(): bool
    {
        return !$this->paysInviter() && !$this->paysInvited();
    }
}
