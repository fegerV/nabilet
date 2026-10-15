<?php

declare(strict_types=1);

namespace Nabilet\Modules\Sessions\Domain;

use Nabilet\Modules\Sessions\StateMachines\SessionStateMachine;

/**
 * Факты и решение об окне продаж одного сеанса — фреймворк-фри.
 *
 * ПОЧЕМУ ВЫНЕСЕНО ИЗ МОДЕЛИ
 *   `Session::isSellableAt()` — гейт, на котором стоит вся покупка (корзина,
 *   бронирование, чекаут), и самый частый вопрос к нему — «а если покупатель
 *   клубный?». Ответ «начало окна сдвигается на N часов» — чистое правило про
 *   даты, и оно обязано быть проверяемым без базы: `tests/run.php` не грузит
 *   фреймворк, а Eloquent-модель в нём не существует. Поэтому модель
 *   (`Session`) только передаёт сюда свои атрибуты, а решение живёт тут.
 *
 * ДВА НЕЗАВИСИМЫХ ГЕЙТА (ТЗ §13)
 *   Оперативный переключатель (`status = on_sale` — «стоп-кран» организатора) и
 *   запланированное окно (`sales_start_at`/`sales_end_at`). Статус, которого нет
 *   в `SessionStateMachine::sellable()`, не продаётся никогда — ранний доступ
 *   (см. ниже) его не ослабляет.
 *
 * РАННИЙ ДОСТУП КЛУБА (фаза 3b, `docs/LOYALTY-PLAN.md`)
 *   `$earlyAccessHours > 0` сдвигает НАЧАЛО окна назад на указанное число часов
 *   — «клубная» очередь продажи впереди общей. Насколько конкретный покупатель
 *   «клубный», решает `LoyaltySettingsService`; здесь достаточно числа часов:
 *   правило «когда продаём» остаётся в одном месте на все контуры.
 *
 *   Сдвиг делается КОПИЕЙ даты: `sales_start_at` у модели приходит мутабельным
 *   Carbon, и сдвиг «на месте» испортил бы атрибут на весь запрос.
 */
final class SalesWindow
{
    public function __construct(
        public readonly string $status,
        public readonly ?\DateTimeInterface $salesStartAt,
        public readonly ?\DateTimeInterface $salesEndAt,
    ) {
    }

    /**
     * Оценка окна на момент `$now` с учётом раннего доступа.
     *
     * `$earlyAccessHours = 0` (или null) — общая продажа, поведение прежнее.
     */
    public function assess(?\DateTimeInterface $now = null, int $earlyAccessHours = 0): SalesWindowDecision
    {
        $now ??= new \DateTimeImmutable('now');

        if (! in_array($this->status, SessionStateMachine::sellable(), true)) {
            return SalesWindowDecision::blocked(
                SalesWindowDecision::BLOCKED_STATUS,
                sprintf(
                    'session status is "%s"; tickets are sold only while the session is "on_sale".',
                    $this->status
                )
            );
        }

        $opensAt = $this->effectiveStart($earlyAccessHours);

        if ($opensAt !== null && $now < $opensAt) {
            if ($earlyAccessHours > 0) {
                return SalesWindowDecision::blocked(
                    SalesWindowDecision::BLOCKED_BEFORE_EARLY_ACCESS,
                    sprintf(
                        'club early access opens at %s (public sales at %s).',
                        $opensAt->format(\DateTimeInterface::ATOM),
                        $this->salesStartAt?->format(\DateTimeInterface::ATOM) ?? ''
                    )
                );
            }

            return SalesWindowDecision::blocked(
                SalesWindowDecision::BLOCKED_BEFORE_OPEN,
                sprintf('sales open at %s.', $opensAt->format(\DateTimeInterface::ATOM))
            );
        }

        if ($this->salesEndAt !== null && $now > $this->salesEndAt) {
            return SalesWindowDecision::blocked(
                SalesWindowDecision::BLOCKED_AFTER_CLOSE,
                sprintf('sales closed at %s.', $this->salesEndAt->format(\DateTimeInterface::ATOM))
            );
        }

        return SalesWindowDecision::sellable();
    }

    public function isSellableAt(?\DateTimeInterface $now = null, int $earlyAccessHours = 0): bool
    {
        return $this->assess($now, $earlyAccessHours)->sellable;
    }

    /** Текст причины отказа для конверта §66; при открытых продажах — «продаём». */
    public function blockReason(?\DateTimeInterface $now = null, int $earlyAccessHours = 0): string
    {
        $decision = $this->assess($now, $earlyAccessHours);

        return $decision->sellable ? 'sales are open.' : $decision->reason;
    }

    /**
     * Момент открытия продаж С УЧЁТОМ раннего доступа.
     *
     * Копия, а не сдвиг исходной даты: у модели `sales_start_at` — мутабельный
     * Carbon, и `modify()` на нём уехал бы в атрибут модели.
     */
    private function effectiveStart(int $earlyAccessHours): ?\DateTimeInterface
    {
        if ($this->salesStartAt === null || $earlyAccessHours < 1) {
            return $this->salesStartAt;
        }

        return \DateTimeImmutable::createFromInterface($this->salesStartAt)
            ->modify(sprintf('-%d hours', $earlyAccessHours));
    }
}
