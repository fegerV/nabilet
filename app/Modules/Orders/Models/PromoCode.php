<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Nabilet\Modules\Pricing\Domain\PromoCodeDefinition;

/**
 * Промокод (ТЗ §86) — таблица `promo_codes`.
 *
 * ЧТО БЫЛО НЕ ТАК
 *
 * Модель описывала колонки, которых в схеме никогда не было: `type`, `value`,
 * `max_uses`, `used_count`, `expires_at`, `is_active`. Реальная таблица
 * (`database/migrations/2026_09_20_000400_004_sales.php`) содержит
 * `discount_type`, `value_amount`, `value_percent`, `currency`, `scope`,
 * `event_id`, `event_category_id`, `min_order_amount`, `max_redemptions`,
 * `per_user_limit`, `redemptions_count`, `status`, `valid_from`, `valid_until`
 * и мягкое удаление. Запрос через старую модель падал бы на неизвестной
 * колонке, а «привычка» к `is_active` рано или поздно породила бы условие
 * `where('is_active', true)` против несуществующего поля.
 *
 * Поля приведены в соответствие со схемой; `toDefinition()` переводит строку
 * в доменный value object `PromoCodeDefinition`, который понимает движок цен.
 */
class PromoCode extends Model
{
    use SoftDeletes;

    protected $table = 'promo_codes';

    protected $fillable = [
        'public_id',
        'organization_id',
        'code',
        'discount_type',
        'value_amount',
        'value_percent',
        'currency',
        'scope',
        'event_id',
        'event_category_id',
        'min_order_amount',
        'max_redemptions',
        'per_user_limit',
        'redemptions_count',
        'status',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'value_amount' => 'integer',
        // DECIMAL(5,2): PDO отдаёт строку ("10.50"). Приведение к float здесь
        // означало бы float в денежной арифметике — базисные пункты считаются
        // из строки в toDefinition(), см. PromoCodeDefinition::fromPercentString.
        'value_percent' => 'string',
        'min_order_amount' => 'integer',
        'max_redemptions' => 'integer',
        'per_user_limit' => 'integer',
        'redemptions_count' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // CHAR(26) public_id обязателен везде в этой схеме — генерируем ULID base32.
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = (string) Str::ulid()->toBase32();
            }
        });
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    /**
     * Доменное представление для движка цен (`PromoEvaluator`).
     *
     * Процент переводится в целые базисные пункты СТРОКОЙ: (float)"10.50"*100
     * даёт 1050.0000000000001 на части сборок PHP, и отбрасывание хвоста молча
     * превратило бы код 10,5% в код 10%.
     */
    public function toDefinition(): PromoCodeDefinition
    {
        $percent = trim((string) ($this->value_percent ?? '0'));
        $bp = 0;

        if (preg_match('/^(\d*)(?:\.(\d*))?$/', $percent, $m) && ($m[1] !== '' || ($m[2] ?? '') !== '')) {
            $bp = ((int) ($m[1] === '' ? '0' : $m[1])) * 100
                + (int) str_pad(substr($m[2] ?? '', 0, 2), 2, '0');
        }

        return new PromoCodeDefinition(
            code: (string) $this->code,
            discountType: (string) $this->discount_type,
            valueAmount: (int) $this->value_amount,
            valuePercentBasisPoints: $bp,
            currency: (string) ($this->currency ?? 'RUB'),
            scope: (string) $this->scope,
            eventId: $this->event_id === null ? null : (int) $this->event_id,
            eventCategoryId: $this->event_category_id === null ? null : (int) $this->event_category_id,
            minOrderAmount: (int) $this->min_order_amount,
            maxRedemptions: $this->max_redemptions === null ? null : (int) $this->max_redemptions,
            perUserLimit: (int) ($this->per_user_limit ?? 1),
            redemptionsCount: (int) ($this->redemptions_count ?? 0),
            status: (string) $this->status,
            validFrom: $this->valid_from?->toDateTimeImmutable(),
            validUntil: $this->valid_until?->toDateTimeImmutable(),
        );
    }
}
