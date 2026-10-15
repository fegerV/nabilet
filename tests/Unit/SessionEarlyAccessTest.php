<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use DateTimeImmutable;
use Nabilet\Modules\Sessions\Domain\SalesWindow;
use Nabilet\Modules\Sessions\Domain\SalesWindowDecision;
use Nabilet\Modules\Sessions\StateMachines\SessionStateMachine;
use Nabilet\Tests\Support\TestCase;

/**
 * Ранний доступ клуба (фаза 3b): чистое правило окна продаж `Domain\SalesWindow`.
 *
 * БЕЗ БАЗЫ И БЕЗ ФРЕЙМВОРКА — отсюда и вынесение из модели: `tests/run.php` не
 * грузит Eloquent, а гейт, на котором стоит вся покупка, обязан проверяться
 * везде. Модель (`Session::isSellableAt()`) теперь только передаёт сюда свои
 * атрибуты.
 *
 * ЗДЕСЬ ПРОВЕРЯЕТСЯ РЕШЕНИЕ, А НЕ ТО, КОМУ ЕГО ПРИМЕНЯТЬ
 *   «На сколько часов сдвинуть начало» считает `LoyaltySettingsService` (кто
 *   клубный); тут — что сдвиг делает с окном: открывает продажу раньше
 *   публичного старта, но не ослабляет ни статус, ни конец окна.
 */
final class SessionEarlyAccessTest extends TestCase
{
    private const PUBLIC_START = '2026-12-01 18:00:00';
    private const PUBLIC_END = '2026-12-31 18:00:00';

    private function window(string $status = SessionStateMachine::ON_SALE): SalesWindow
    {
        return new SalesWindow(
            $status,
            new DateTimeImmutable(self::PUBLIC_START),
            new DateTimeImmutable(self::PUBLIC_END),
        );
    }

    public function test_before_public_start_without_early_access(): void
    {
        $window = $this->window();

        self::assertFalse($window->isSellableAt(new DateTimeImmutable('2026-11-30 18:00:00')));
        self::assertTrue($window->isSellableAt(new DateTimeImmutable(self::PUBLIC_START)));
        self::assertTrue($window->isSellableAt(new DateTimeImmutable('2026-12-15 12:00:00')));
        self::assertSame('sales open at 2026-12-01T18:00:00+00:00.', $window->blockReason(new DateTimeImmutable('2026-11-30 18:00:00')));
    }

    public function test_early_access_opens_the_window_before_public_start(): void
    {
        $window = $this->window();

        // За сутки до публичного старта клубный покупатель уже проходит гейт.
        self::assertTrue($window->isSellableAt(new DateTimeImmutable('2026-11-30 19:00:00'), 24));
        // Ровно на границе сдвига тоже.
        self::assertTrue($window->isSellableAt(new DateTimeImmutable('2026-11-30 18:00:00'), 24));
        // За сутки до старта без сдвига (0 часов) — нет.
        self::assertFalse($window->isSellableAt(new DateTimeImmutable('2026-11-30 19:00:00'), 0));
    }

    public function test_before_the_early_access_window_the_gate_is_still_closed(): void
    {
        $window = $this->window();

        self::assertFalse($window->isSellableAt(new DateTimeImmutable('2026-11-29 18:00:00'), 24));
        self::assertFalse($window->isSellableAt(new DateTimeImmutable('2026-11-30 17:59:59'), 24));

        $decision = $window->assess(new DateTimeImmutable('2026-11-25 10:00:00'), 24);
        self::assertFalse($decision->sellable);
        self::assertSame(SalesWindowDecision::BLOCKED_BEFORE_EARLY_ACCESS, $decision->verdict);
    }

    public function test_status_gate_is_not_relaxed_by_early_access(): void
    {
        // «Стоп-кран» организатора сильнее любой клубной очереди: ранний доступ
        // открывает окно раньше, но не продаёт сеанс не в статусе `on_sale`.
        foreach ([
            SessionStateMachine::DRAFT,
            SessionStateMachine::SCHEDULED,
            SessionStateMachine::CANCELLED,
            SessionStateMachine::COMPLETED,
            SessionStateMachine::SOLD_OUT,
            SessionStateMachine::CLOSED,
        ] as $status) {
            $window = $this->window($status);

            self::assertFalse(
                $window->isSellableAt(new DateTimeImmutable('2026-11-30 19:00:00'), 24),
                "Early access must not sell a session in status {$status}."
            );

            self::assertSame(
                SalesWindowDecision::BLOCKED_STATUS,
                $window->assess(new DateTimeImmutable('2026-11-30 19:00:00'), 24)->verdict
            );
        }
    }

    public function test_early_access_does_not_bypass_the_end_of_the_window(): void
    {
        $window = $this->window();

        self::assertFalse($window->isSellableAt(new DateTimeImmutable('2027-01-02 10:00:00'), 24));
        self::assertSame(
            SalesWindowDecision::BLOCKED_AFTER_CLOSE,
            $window->assess(new DateTimeImmutable('2027-01-02 10:00:00'), 24)->verdict
        );
    }

    public function test_a_window_without_a_start_date_keeps_its_semantics(): void
    {
        // Нет `sales_start_at` — окно не ограничено началом, и сдвигу нечего
        // применяться: раньше «сейчас» продажа начинаться не может.
        $window = new SalesWindow(
            SessionStateMachine::ON_SALE,
            null,
            new DateTimeImmutable(self::PUBLIC_END),
        );

        self::assertTrue($window->isSellableAt(new DateTimeImmutable('1990-01-01'), 24));
    }

    public function test_the_shift_is_applied_to_a_copy_not_to_the_source_date(): void
    {
        $start = new DateTimeImmutable(self::PUBLIC_START);
        $window = new SalesWindow(SessionStateMachine::ON_SALE, $start, new DateTimeImmutable(self::PUBLIC_END));

        // Два вызова подряд с разным сдвигом: если бы сдвиг менял исходную дату,
        // второй вызов сравнивал бы уже сдвинутую, и «граница» уехала бы ещё на
        // сутки назад.
        self::assertTrue($window->isSellableAt(new DateTimeImmutable('2026-11-30 19:00:00'), 24));
        self::assertTrue($window->isSellableAt(new DateTimeImmutable('2026-11-29 19:00:00'), 48));

        self::assertSame(self::PUBLIC_START, $start->format('Y-m-d H:i:s'));
    }

    public function test_the_block_reason_names_the_club_moment_for_an_early_buyer(): void
    {
        $window = $this->window();

        $reason = $window->blockReason(new DateTimeImmutable('2026-11-25 10:00:00'), 24);

        self::assertStringContainsString('club early access opens at', $reason);
        self::assertStringContainsString('2026-11-30T18:00:00+00:00', $reason);
        self::assertStringContainsString('public sales at 2026-12-01T18:00:00+00:00', $reason);

        // Без сдвига текст прежний — публичный старт.
        self::assertStringContainsString(
            'sales open at 2026-12-01T18:00:00+00:00.',
            $window->blockReason(new DateTimeImmutable('2026-11-25 10:00:00'))
        );
    }

    public function test_an_open_window_reports_no_block(): void
    {
        $window = $this->window();

        $decision = $window->assess(new DateTimeImmutable('2026-12-15 10:00:00'), 24);

        self::assertTrue($decision->sellable);
        self::assertSame('sales are open.', $window->blockReason(new DateTimeImmutable('2026-12-15 10:00:00'), 24));
    }
}
