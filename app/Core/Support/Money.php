<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * Immutable money value object.
 *
 * RULE: money is ALWAYS an integer in minor units (kopecks/cents) plus a currency
 * code. Never float, never a bare decimal string. Binary floating point cannot
 * represent 0.1 exactly, and a ticketing system performs thousands of additions,
 * percentage discounts and commission splits — the rounding errors compound into
 * real accounting discrepancies.
 *
 * @see docs/ARCHITECTURE.md — "Money"
 */
final class Money implements \JsonSerializable, \Stringable
{
    private function __construct(
        private readonly int $minorUnits,
        private readonly string $currency,
    ) {
        if ($currency === '' || strlen($currency) !== 3) {
            throw new DomainRuleViolation(
                sprintf('Currency must be a 3-letter ISO 4217 code, got "%s".', $currency),
                'INVALID_CURRENCY'
            );
        }
    }

    public static function of(int $minorUnits, string $currency = 'RUB'): self
    {
        return new self($minorUnits, strtoupper($currency));
    }

    public static function zero(string $currency = 'RUB'): self
    {
        return new self(0, strtoupper($currency));
    }

    /**
     * Parse a human decimal amount ("5000.50") into minor units without ever
     * touching float arithmetic — string maths only.
     */
    public static function fromDecimal(string $amount, string $currency = 'RUB', int $scale = 2): self
    {
        $amount = trim(str_replace([',', ' '], ['.', ''], $amount));

        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $amount, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new DomainRuleViolation(
                sprintf('Cannot parse "%s" as a decimal amount.', $amount),
                'INVALID_AMOUNT'
            );
        }

        $sign = $m[1] === '-' ? -1 : 1;
        $whole = $m[2] === '' ? '0' : $m[2];
        $fraction = str_pad(substr($m[3] ?? '', 0, $scale), $scale, '0');

        return new self($sign * (int) ($whole . $fraction), strtoupper($currency));
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function multiply(int|float $factor): self
    {
        return new self((int) round($this->minorUnits * $factor), $this->currency);
    }

    /**
     * Apply a percentage discount. Rounds half-up so that a 10% discount on
     * 5300 minor units yields 530, not 529.
     */
    public function percentage(float $percent): self
    {
        return new self((int) round($this->minorUnits * $percent / 100), $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->minorUnits === $other->minorUnits;
    }

    /**
     * Split into $parts shares without losing a single minor unit.
     *
     * 1000 across 3 parts => [334, 333, 333]. The remainder is distributed one
     * unit at a time to the earliest shares. This matters for revenue splits and
     * per-ticket refunds: the sum of the parts MUST always equal the whole.
     *
     * @return list<self>
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new DomainRuleViolation('Cannot allocate money into fewer than 1 part.', 'INVALID_ALLOCATION');
        }

        $base = intdiv($this->minorUnits, $parts);
        $remainder = $this->minorUnits % $parts;

        $shares = [];
        for ($i = 0; $i < $parts; $i++) {
            $shares[] = new self($base + ($i < $remainder ? 1 : 0), $this->currency);
        }

        return $shares;
    }

    /**
     * Multiply by a rational quantity (e.g. 2 tickets) preserving total accuracy.
     */
    public function times(int $quantity): self
    {
        if ($quantity < 0) {
            throw new DomainRuleViolation('Quantity cannot be negative.', 'INVALID_QUANTITY');
        }

        return new self($this->minorUnits * $quantity, $this->currency);
    }

    public function toDecimal(): string
    {
        $sign = $this->minorUnits < 0 ? '-' : '';
        $abs = abs($this->minorUnits);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    public function format(string $locale = 'ru_RU'): string
    {
        $symbol = match ($this->currency) {
            'RUB' => '₽',
            'USD' => '$',
            'EUR' => '€',
            default => ' ' . $this->currency,
        };

        $formatted = number_format(abs($this->minorUnits) / 100, 2, ',', ' ');

        return ($this->minorUnits < 0 ? '−' : '') . $formatted . ' ' . $symbol;
    }

    /** @return array{amount: int, currency: string, decimal: string} */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->minorUnits,
            'currency' => $this->currency,
            'decimal' => $this->toDecimal(),
        ];
    }

    public function __toString(): string
    {
        return $this->toDecimal() . ' ' . $this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new DomainRuleViolation(
                sprintf('Cannot combine %s with %s.', $this->currency, $other->currency),
                'CURRENCY_MISMATCH'
            );
        }
    }
}
