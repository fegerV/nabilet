<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * A data subject asking us to do something about their data (ТЗ §72, 152-ФЗ).
 *
 * `privacy_requests.status` — словарь из схемы: `ck_privacy_requests_status`
 * допускает ровно `requested`, `in_progress`, `completed`, `rejected`
 * (сплит 017, миграция `2026_10_12_000500`). Секция 026 бандла
 * (`2026_11_03_000100_privacy_guards`, §3.15) довела до базы и словарь типов:
 * `ck_privacy_requests_type` допускает ровно пять значений из `types()` ниже.
 * Оба барьера теперь двойные — класс и база не могут разойтись: значения
 * берутся из констант этого класса. Fail-closed сохранён: нераспознанное
 * значение считается нерабочим, а не валидным.
 *
 * THE DEADLINE IS A PARAMETER, NOT A CONSTANT, and that is deliberate. Neither
 * the ТЗ nor the OpenAPI contract states the response period, and I am not
 * willing to hard-code a statutory figure I cannot verify — a wrong number baked
 * into the one place everything trusts is worse than a number the caller must
 * supply. This is the same decision that was made about the service fee in
 * Pricing: where the specification is silent, the value is passed in, not
 * invented.
 *
 * Записанный дедлайн имеет склеп: секция 026 добавила `privacy_requests.due_at`
 * (`NOT NULL`) — раньше CHECK-класс обязан был вычислять срок, но записать
 * его было некуда, а срок, который нельзя записать, нельзя и проконтролировать.
 * Кого обязывает число — решает создатель строки: он вычисляет `dueAt(slaDays)`
 * и записывает результат вместе со строкой. Уникальность не нарушена: строка
 * рождается ровно с одним дедлайном.
 */
final class PrivacyRequest
{
    public const TYPE_ACCESS = 'access';
    public const TYPE_ERASURE = 'erasure';
    public const TYPE_RECTIFICATION = 'rectification';
    public const TYPE_RESTRICTION = 'restriction';
    public const TYPE_PORTABILITY = 'portability';

    public const REQUESTED = 'requested';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $publicId,
        public readonly string $type,
        public readonly string $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $completedAt = null,
    ) {
        if (! in_array($type, self::types(), true)) {
            throw new DomainRuleViolation(
                sprintf('"%s" is not a privacy request type.', $type),
                'INVALID_PRIVACY_REQUEST_TYPE'
            );
        }

        if (! in_array($status, self::statuses(), true)) {
            throw new DomainRuleViolation(
                sprintf('"%s" is not a privacy request status.', $status),
                'INVALID_PRIVACY_REQUEST_STATUS'
            );
        }

        if (in_array($status, [self::COMPLETED, self::REJECTED], true) && $completedAt === null) {
            throw new DomainRuleViolation(
                sprintf('A %s request must record when it was closed.', $status),
                'MISSING_COMPLETED_AT'
            );
        }

        if ($completedAt !== null && $completedAt < $createdAt) {
            throw new DomainRuleViolation(
                'A request cannot be closed before it was made.',
                'IMPOSSIBLE_REQUEST_TIMELINE'
            );
        }
    }

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::TYPE_ACCESS,
            self::TYPE_ERASURE,
            self::TYPE_RECTIFICATION,
            self::TYPE_RESTRICTION,
            self::TYPE_PORTABILITY,
        ];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::REQUESTED, self::IN_PROGRESS, self::COMPLETED, self::REJECTED];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::REJECTED], true);
    }

    /**
     * @param  int $slaDays the response period; see the class docblock for why it
     *                      is supplied rather than assumed
     */
    public function dueAt(int $slaDays): \DateTimeImmutable
    {
        return $this->createdAt->modify(sprintf('+%d days', $slaDays));
    }

    public function isOverdue(\DateTimeImmutable $now, int $slaDays): bool
    {
        if ($this->isTerminal()) {
            return false; // a closed request is late or on time, never "overdue"
        }

        return $now > $this->dueAt($slaDays);
    }
}
