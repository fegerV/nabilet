<?php

declare(strict_types=1);

namespace Nabilet\Modules\Pricing\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * A promo code as the pricing engine sees it — a pure value object, no ORM.
 *
 * ТЗ §86 lists the conditions a code may carry: fixed / percent / first_purchase
 * / event / category / date / quantity. They map onto this object as:
 *
 *   fixed | percent   -> discountType + valueAmount | valuePercentBasisPoints
 *   event | category  -> scope + eventId | eventCategoryId
 *   first_purchase    -> scope
 *   date              -> validFrom / validUntil
 *   quantity          -> maxRedemptions / perUserLimit / redemptionsCount
 *
 * PERCENT IS STORED AS INTEGER BASIS POINTS, NOT AS A FLOAT.
 * The column is DECIMAL(5,2), and PDO hands that back as a string. Converting
 * "10.50" to 1050 basis points immediately means the discount arithmetic is
 * exact integer division — no `0.1 + 0.2` anywhere near the money.
 */
final class PromoCodeDefinition
{
    public const TYPE_FIXED = 'fixed';
    public const TYPE_PERCENT = 'percent';

    public const SCOPE_ALL = 'all';
    public const SCOPE_EVENT = 'event';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_FIRST_PURCHASE = 'first_purchase';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_EXPIRED = 'expired';

    /**
     * Все допустимые статусы промокода.
     *
     * ВЛАДЕЛЕЦ СЛОВАРЯ. `promo_codes.status` — `VARCHAR(32)` БЕЗ CHECK-ограничения
     * (в отличие от `orders.status` и `carts.status`), поэтому база здесь ни от
     * чего не защищает: единственный барьер — этот метод. Пока список был
     * выписан руками в `PromoCodeService`, правка словаря требовала найти все
     * копии, а несогласованная копия означала молча принимаемый мусорный статус.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_EXPIRED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public function __construct(
        public readonly string $code,
        public readonly string $discountType = self::TYPE_PERCENT,
        public readonly int $valueAmount = 0,
        public readonly int $valuePercentBasisPoints = 0,
        public readonly string $currency = 'RUB',
        public readonly string $scope = self::SCOPE_ALL,
        public readonly ?int $eventId = null,
        public readonly ?int $eventCategoryId = null,
        public readonly int $minOrderAmount = 0,
        public readonly ?int $maxRedemptions = null,
        public readonly int $perUserLimit = 1,
        public readonly int $redemptionsCount = 0,
        public readonly string $status = self::STATUS_ACTIVE,
        public readonly ?\DateTimeImmutable $validFrom = null,
        public readonly ?\DateTimeImmutable $validUntil = null,
    ) {
        if ($valueAmount < 0) {
            throw new DomainRuleViolation('A fixed discount cannot be negative.', 'INVALID_PROMO_VALUE');
        }

        if ($valuePercentBasisPoints < 0 || $valuePercentBasisPoints > 10000) {
            throw new DomainRuleViolation(
                sprintf('A percentage discount must be 0..10000 basis points, got %d.', $valuePercentBasisPoints),
                'INVALID_PROMO_VALUE'
            );
        }

        if ($discountType === self::TYPE_PERCENT && $valuePercentBasisPoints === 0) {
            throw new DomainRuleViolation(
                'A percent promo code with 0% is almost certainly a mistake: it would accept a code and then discount nothing.',
                'INVALID_PROMO_VALUE'
            );
        }

        if ($discountType === self::TYPE_FIXED && $valueAmount === 0) {
            throw new DomainRuleViolation(
                'A fixed promo code with amount 0 discounts nothing; reject it at creation instead.',
                'INVALID_PROMO_VALUE'
            );
        }

        // ck_promo_codes_window: valid_until >= valid_from.
        if ($validFrom !== null && $validUntil !== null && $validUntil < $validFrom) {
            throw new DomainRuleViolation(
                'A promo code cannot expire before it starts.',
                'INVALID_PROMO_WINDOW'
            );
        }

        if ($scope === self::SCOPE_EVENT && $eventId === null) {
            throw new DomainRuleViolation('Scope "event" requires an eventId.', 'INVALID_PROMO_SCOPE');
        }

        if ($scope === self::SCOPE_CATEGORY && $eventCategoryId === null) {
            throw new DomainRuleViolation('Scope "category" requires an eventCategoryId.', 'INVALID_PROMO_SCOPE');
        }
    }

    /**
     * ЕДИНСТВЕННЫЙ ВЛАДЕЛЕЦ правила «строка процента → целые базисные пункты».
     *
     * Столбец — DECIMAL(5,2), PDO отдаёт его строкой ("10.50"). Переводим в
     * базисные пункты СТРОКОВОЙ математикой, а не через float: (float)"10.50"*100
     * даёт 1050.0000000000001 на части сборок, и отбрасывание хвоста молча
     * превратило бы код 10,5% в код 10%.
     *
     * Этот же парсер используют и `fromPercentString()`, и `PromoCode::toDefinition()`
     * — иначе два пути построения домена могли бы разойтись (один бросает на
     * битом вводе, другой молча отдавал 0%). Невалидный/пустой ввод бросает
     * DomainRuleViolation: промокод с битым процентом не должен превращаться в 0%
     * (это «скидка, которая ничего не скидывает» — хуже явной ошибки).
     *
     * @throws DomainRuleViolation
     */
    public static function percentToBasisPoints(string $percent): int
    {
        $percent = trim($percent);

        if (! preg_match('/^(\d*)(?:\.(\d*))?$/', $percent, $m) || ($m[1] === '' && ($m[2] ?? '') === '')) {
            throw new DomainRuleViolation(
                sprintf('Cannot parse "%s" as a percentage.', $percent),
                'INVALID_PROMO_VALUE'
            );
        }

        $whole = $m[1] === '' ? '0' : $m[1];
        // Дополняем до 2 знаков; эти два десятичных разряда И ЕСТЬ остаток в
        // базисных пунктах: "10.5" -> "10" + "50" -> 1050.
        $fraction = str_pad(substr($m[2] ?? '', 0, 2), 2, '0');

        return ((int) $whole) * 100 + (int) $fraction;
    }

    /**
     * Build from the DECIMAL(5,2) percent string MySQL returns ("10.50").
     *
     * String maths, not float: (float)"10.50" * 100 is 1050.0000000000001 on some
     * builds, and truncating that would quietly turn a 10.5% code into a 10% one.
     * Сам разбор строки вынесен в {@see self::percentToBasisPoints()}.
     */
    public static function fromPercentString(string $percent, string $code = '', string $currency = 'RUB'): self
    {
        return new self(
            code: $code,
            discountType: self::TYPE_PERCENT,
            valuePercentBasisPoints: self::percentToBasisPoints($percent),
            currency: $currency,
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
