<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Loyalty\Domain\BonusPolicy;

/**
 * Баланс бонусного счёта — схема `BonusBalance` контракта.
 *
 * ПОЧЕМУ ДВА ЧИСЛА ОБ ОДНОМ ЗНАЧЕНИИ
 *   `balance` — минорные единицы, как и все деньги в API (`MoneyTotals`): это
 *   то, что лежит в базе, и то, чем считает сервер. `bonuses` — тот же баланс в
 *   «бонусах» витрины по курсу `BonusPolicy::MINOR_UNITS_PER_BONUS` (1 бонус =
 *   1 ₽ = 100 минорных единиц).
 *
 *   Отдать только минорные единицы значило бы перенести курс в клиента: экран
 *   «У вас 2 500 бонусов» считался бы как `balance / 100` в вёрстке, и правка
 *   курса потребовала бы найти все места, где зашита сотня, — ровно то
 *   расхождение, от которого предостерегает `docs/LOYALTY-PLAN.md`
 *   («делит их представление — этот коэффициент, и только он»). Здесь курс
 *   применяет сервер, и клиент показывает готовое число.
 *
 *   `bonuses` — производное от `balance`, а не второй источник правды: обе
 *   цифры приходят из одного `Money`, и разойтись не могут.
 */
class BonusBalanceResource extends JsonResource
{
    /**
     * @param  Money  $resource  баланс покупателя; ноль, если счёта ещё нет
     */
    public function toArray(Request $request): array
    {
        /** @var Money $balance */
        $balance = $this->resource;

        return [
            'balance' => $balance->minorUnits(),
            'bonuses' => BonusPolicy::displayUnits($balance->minorUnits()),
            'currency' => $balance->currency(),
        ];
    }
}
