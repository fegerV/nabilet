<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Support;

/**
 * С какой стороны полотна нарисована сцена: `top` или `bottom`.
 *
 * Один владелец правила для витрины и embed-виджета. Раньше расчёт жил приватным
 * методом `InventoryController::stageSide()`: embed-карта зала не могла его
 * вызвать и молча рисовала сцену сверху даже в залах, где ряд 1 стоит внизу.
 *
 * Медиана Y мест, а не среднее: боковые блоки и балкон уводят среднее от
 * реальной середины партера. Нет сцены или мест — `top`, как исторически.
 */
final class StageSide
{
    public const TOP = 'top';

    public const BOTTOM = 'bottom';

    public static function fromSchema(?array $schema): string
    {
        if ($schema === null) {
            return self::TOP;
        }

        $stage = null;
        foreach ((array) ($schema['staticObjects'] ?? []) as $object) {
            if (is_array($object) && ($object['kind'] ?? null) === 'stage') {
                $stage = $object;
                break;
            }
        }
        if ($stage === null) {
            return self::TOP;
        }

        $seatYs = [];
        foreach ((array) ($schema['sectors'] ?? []) as $sector) {
            if (! is_array($sector)) {
                continue;
            }
            $offsetY = (float) ($sector['y'] ?? 0);
            foreach ((array) ($sector['seats'] ?? []) as $seat) {
                if (is_array($seat)) {
                    $seatYs[] = $offsetY + (float) ($seat['y'] ?? 0);
                }
            }
        }
        if ($seatYs === []) {
            return self::TOP;
        }

        sort($seatYs);
        $medianY = $seatYs[intdiv(count($seatYs), 2)];
        $stageCenterY = (float) ($stage['y'] ?? 0) + (float) ($stage['height'] ?? 0) / 2;

        return $stageCenterY > $medianY ? self::BOTTOM : self::TOP;
    }
}
