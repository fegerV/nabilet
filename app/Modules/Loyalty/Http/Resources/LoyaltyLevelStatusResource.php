<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Статус уровня «Клуба зрителей» — схема `LoyaltyLevelStatus` контракта.
 *
 * ПОЧЕМУ ОДИН ОБЪЕКТ НА ВСЁ
 *   Фронту нужен и текущий уровень, и прогресс до следующего, и число оплаченных
 *   заказов — всё сразу, одним запросом к `/me/loyalty-level`. Разносить это по
 *   трём эндпоинтам значило бы трижды считать COUNT заказов и плодить точки,
 *   которые могут разойтись. Поэтому один ресурс собирает готовый снимок.
 *
 * `next_level` — это схема `LoyaltyLevel` (без прогресса), вложенная в статус:
 * на самом верхнем уровне её нет (NULL), и фронт показывает «максимальный
 * уровень» без полосы прогресса.
 *
 * @property array $resource
 */
final class LoyaltyLevelStatusResource extends JsonResource
{
    /**
     * @param  array{
     *     slug: string,
     *     name: string,
     *     min_paid_orders: int,
     *     paid_orders: int,
     *     next_level: array{slug: string, name: string, min_paid_orders: int}|null,
     *     orders_to_next: int
     * }  $resource
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'slug' => $data['slug'],
            'name' => $data['name'],
            'min_paid_orders' => $data['min_paid_orders'],
            'paid_orders' => $data['paid_orders'],
            'next_level' => $data['next_level'],
            'orders_to_next' => $data['orders_to_next'],
        ];
    }
}
