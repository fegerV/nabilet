<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Уровень «Клуба зрителей» — строка конфига (Старт/Friend/VIP).
 *
 * Модель тонкая: пороги (`min_paid_orders`) и имена правит сид, а не код, поэтому
 * здесь нет бизнес-логики выбора уровня — её забирает `LoyaltyTierPolicy`
 * (фреймворк-фри). Модель только хранит данные и отдаёт их сервису.
 *
 * `public_id` — ULID (26 символов), как и у соседей по схеме; генерируется при
 * создании, чтобы внешний идентификатор не зависел от автоинкрементного `id`.
 */
class LoyaltyLevel extends Model
{
    protected $table = 'loyalty_levels';

    protected $fillable = [
        'public_id',
        'slug',
        'name',
        'min_paid_orders',
        'sort_order',
    ];

    protected $casts = [
        'min_paid_orders' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->public_id)) {
                // 26-символьный ULID в base32 — тот же формат, что у прочих public_id.
                $model->public_id = Str::ulid()->toBase32();
            }
        });
    }

    /**
     * Уровни по возрастанию порога — тот порядок, который ожидает `LoyaltyTierPolicy`.
     *
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('min_paid_orders', 'asc')->orderBy('sort_order', 'asc');
    }
}
