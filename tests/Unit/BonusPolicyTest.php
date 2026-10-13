<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use DateTimeImmutable;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Loyalty\Domain\BonusPolicy;
use Nabilet\Modules\Loyalty\Domain\BonusReason;
use Nabilet\Tests\Support\TestCase;

/**
 * Правила бонусного счёта — там, где клуб теряет деньги.
 *
 * Тесты не про арифметику (она тривиальна), а про ошибки, которые не видны до
 * сверки: кэшбэк, начисленный от subtotal вместо оплаченного итога; лимит
 * списания, посчитанный от баланса вместо доли заказа (и наоборот); округление,
 * оставляющее копейку на каждой тысяче заказов; срок жизни, посчитанный в днях
 * вместо месяцев.
 *
 * Файл исполняется `php tests/run.php` без `vendor/` и без базы — это и есть
 * причина, по которой правила живут в `Domain/` (см. `tools/verify-purity.php`).
 */
final class BonusPolicyTest extends TestCase
{
    // ── начисление ───────────────────────────────────────────────────────────

    public function testAccrualIsFivePercentOfThePaidTotal(): void
    {
        // 5 000,00 ₽ → 250,00 ₽ кэшбэка.
        $this->assertSame(25000, BonusPolicy::accrualFor(Money::of(500000))->minorUnits());
    }

    public function testAccrualRoundsHalfUp(): void
    {
        // 5% от 12 345 = 617,25 → 617 (половина вверх, как у Money::percentage).
        $this->assertSame(617, BonusPolicy::accrualFor(Money::of(12345))->minorUnits());

        // Ровно половина: 5% от 30 = 1,5 → 2, а не 1.
        $this->assertSame(2, BonusPolicy::accrualFor(Money::of(30))->minorUnits());
    }

    public function testAccrualOnAZeroOrderIsZero(): void
    {
        $this->assertTrue(BonusPolicy::accrualFor(Money::zero())->isZero());
    }

    public function testAccrualKeepsTheOrderCurrency(): void
    {
        $accrual = BonusPolicy::accrualFor(Money::of(500000, 'USD'));

        $this->assertSame('USD', $accrual->currency());
    }

    public function testAccrualOnANegativeTotalIsRefused(): void
    {
        // Отрицательный итог — ошибка вызывающего (CHECK ck_orders_amounts такого
        // заказа не пропустит), а не «ноль бонусов». Тихий ноль спрятал бы её.
        $this->assertThrows(
            DomainRuleViolation::class,
            static fn () => BonusPolicy::accrualFor(Money::of(-1)),
        );
    }

    // ── лимит списания ───────────────────────────────────────────────────────

    public function testSpendCapIsThirtyPercentOfSubtotal(): void
    {
        // Баланс 5 000,00 ₽, заказ 1 000,00 ₽ → списать можно 300,00 ₽.
        $spendable = BonusPolicy::maxSpendable(Money::of(100000), Money::of(500000));

        $this->assertSame(30000, $spendable->minorUnits());
    }

    public function testSpendCapIsLimitedByTheBalance(): void
    {
        // Баланс 10,00 ₽, заказ 1 000,00 ₽ → списать можно только 10,00 ₽:
        // лимит доли заказа не позволяет тратить больше, чем есть на счёте.
        $spendable = BonusPolicy::maxSpendable(Money::of(100000), Money::of(1000));

        $this->assertSame(1000, $spendable->minorUnits());
    }

    public function testSpendCapAcceptsTheExactLimit(): void
    {
        // Баланс ровно равен лимиту — списываем его целиком, без «на единицу меньше».
        $spendable = BonusPolicy::maxSpendable(Money::of(100000), Money::of(30000));

        $this->assertSame(30000, $spendable->minorUnits());
    }

    public function testSpendCapOnANegativeBalanceIsZero(): void
    {
        // Баланс в минус не уходит по CHECK, но защита здесь дешевле, чем
        // отрицательное списание в разборе.
        $this->assertTrue(BonusPolicy::maxSpendable(Money::of(100000), Money::of(-500))->isZero());
    }

    public function testSpendCapOnAZeroSubtotalIsZero(): void
    {
        $this->assertTrue(BonusPolicy::maxSpendable(Money::zero(), Money::of(500000))->isZero());
    }

    public function testSpendCapKeepsTheSubtotalCurrency(): void
    {
        $spendable = BonusPolicy::maxSpendable(Money::of(100000, 'EUR'), Money::zero('EUR'));

        $this->assertSame('EUR', $spendable->currency());
    }

    // ── срок жизни ───────────────────────────────────────────────────────────

    public function testLifetimeIsTwelveCalendarMonths(): void
    {
        $expires = BonusPolicy::expiresAt(new DateTimeImmutable('2026-03-14 19:30:00'));

        // Тот же день и то же время через год — а не «+365 дней».
        $this->assertSame('2027-03-14 19:30:00', $expires->format('Y-m-d H:i:s'));
    }

    public function testLifetimeFromTheEndOfJanuaryStaysAtTheEndOfJanuary(): void
    {
        // 31 января + 12 месяцев — 31 января, а не 3 марта: в 12 месяцах
        // переполнения месяца не бывает (в отличие от «+1 месяц»).
        $this->assertSame(
            '2027-01-31',
            BonusPolicy::expiresAt(new DateTimeImmutable('2026-01-31'))->format('Y-m-d')
        );
    }

    public function testLifetimeFromTheTwentyNinthOfFebruaryRollsToMarchFirst(): void
    {
        // 29 февраля через год не существует (2029 не високосный). PHP переносит
        // дату на 1 марта, и это зафиксировано осознанно: выбрать можно было
        // 28 февраля или 1 марта, и выбран день БОЛЬШЕ — ошибка в сторону
        // «бонус пожил на сутки дольше» не отнимает у покупателя то, что он уже
        // видит на счёте.
        $this->assertSame(
            '2029-03-01',
            BonusPolicy::expiresAt(new DateTimeImmutable('2028-02-29'))->format('Y-m-d')
        );
    }

    public function testLifetimeIsExactlyTwelveMonthsNotThirteen(): void
    {
        $accrued = new DateTimeImmutable('2026-06-15 08:00:00');

        $this->assertSame(
            '2027-06-15 08:00:00',
            BonusPolicy::expiresAt($accrued)->format('Y-m-d H:i:s')
        );
    }

    // ── курс отображения ─────────────────────────────────────────────────────

    public function testDisplayUnitsDivideMinorUnitsByOneHundred(): void
    {
        // 250,00 ₽ на счёте — это 250 бонусов.
        $this->assertSame(250, BonusPolicy::displayUnits(25000));
    }

    public function testDisplayUnitsRoundDownSoTheBalanceIsNeverOverpromised(): void
    {
        // 1,99 ₽ — это 1 бонус и 99 копеек. Показать «2 бонуса» значило бы
        // пообещать больше, чем есть на счёте.
        $this->assertSame(1, BonusPolicy::displayUnits(199));
        $this->assertSame(0, BonusPolicy::displayUnits(99));
    }

    public function testMinorUnitsForIsTheInverseOfDisplayUnits(): void
    {
        $this->assertSame(25000, BonusPolicy::minorUnitsFor(250));
        $this->assertSame(250, BonusPolicy::displayUnits(BonusPolicy::minorUnitsFor(250)));
    }

    // ── словарь причин ───────────────────────────────────────────────────────

    public function testReasonVocabularyIsClosedAndRecognised(): void
    {
        foreach (BonusReason::all() as $reason) {
            $this->assertTrue(BonusReason::isKnown($reason), sprintf('"%s" must be known', $reason));
        }

        $this->assertFalse(BonusReason::isKnown('teleported'));
        $this->assertSame(
            ['accrual', 'registration', 'first_purchase', 'spend', 'refund', 'expiry'],
            BonusReason::all(),
        );
    }

    public function testReasonVocabularyHasNoDuplicates(): void
    {
        $this->assertSame(
            count(BonusReason::all()),
            count(array_unique(BonusReason::all())),
        );
    }
}
