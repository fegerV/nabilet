<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * One consent, for one purpose, by one subject (ТЗ §72, 152-ФЗ).
 *
 * `consents.status` — словарь из схемы: `ck_consents_status` допускает ровно
 * `granted`, `withdrawn`, `expired` (spec §«consents», сплит
 * `2026_10_12_000500_add_status_vocabularies.php`). Он применяется
 * FAIL-CLOSED и здесь: только `granted` разрешает обработку, незнакомый
 * статус согласием не является. Это направление важно — если будущий релиз
 * начнёт писать `opted_in`, класс откажется считать это разрешением, а не
 * угадает. До появления CHECK тот же барьер держался только этим классом;
 * теперь их два, и расходятся они не могут: значения берутся отсюда.
 *
 * EXACTLY ONE SUBJECT. Согласие принадлежит ровно одному субъекту: правило
 * держит КОНСТРУКТОР (ниже, INVALID_CONSENT_SUBJECT) — и только он. Предложить
 * его базе (рецепт §3.15 — CHECK `(user_id IS NOT NULL) <> (anonymous_id IS
 * NOT NULL)`) MySQL 8.4 не даёт: ошибка 3823, колонка `user_id` нужна
 * referential action'у `fk_consents_user … ON DELETE SET NULL`, а такие
 * колонки в CHECK запрещены; триггер не выход, потому что MySQL не активирует
 * триггеры для FK-действий. Согласие, не принадлежащее никому, нельзя ни
 * предъявить по запросу субъекта, ни отозвать — это шум, похожий на
 * доказательство; оба идентификатора сразу — один человек под двумя
 * личностями, и ответ по любой из них будет неполным.
 *
 * `withdrawnAt` живёт в колонке. Секция 026 бандла (`2026_11_03_000100_privacy_guards`)
 * добавила `consents.withdrawn_at` и запретила строку `withdrawn` без момента
 * отзыва (`ck_consents_withdrawn_has_moment`) — раньше дефект был тот же, что
 * `tickets.revoked_at`: факту отзыва некуда было записаться, и домен нёс его
 * один. Теперь барьер двойной: строка `withdrawn` без `withdrawn_at`
 * отвергается и конструктором (ниже), и базой. Отзыв — НОВАЯ строка (журнал,
 * история не переписывается), и она несёт момент отзыва в себе.
 */
final class ConsentRecord
{
    public const GRANTED = 'granted';
    public const WITHDRAWN = 'withdrawn';
    public const EXPIRED = 'expired';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::GRANTED, self::WITHDRAWN, self::EXPIRED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public function __construct(
        public readonly string $type,
        public readonly string $status,
        public readonly \DateTimeImmutable $grantedAt,
        public readonly ?int $userId = null,
        public readonly ?string $anonymousId = null,
        public readonly ?string $policyVersion = null,
        public readonly ?\DateTimeImmutable $withdrawnAt = null,
    ) {
        if ($type === '') {
            throw new DomainRuleViolation(
                'A consent must name its purpose; "consent" without one is not consent.',
                'INVALID_CONSENT'
            );
        }

        if (! self::isValid($status)) {
            throw new DomainRuleViolation(
                sprintf('"%s" is not a consent status.', $status),
                'INVALID_CONSENT_STATUS'
            );
        }

        // Exactly one: XOR.
        if (($userId === null) === ($anonymousId === null)) {
            throw new DomainRuleViolation(
                'A consent must be attached to exactly one subject — a user or an '
                . 'anonymous id, never neither and never both.',
                'INVALID_CONSENT_SUBJECT'
            );
        }

        if ($status === self::WITHDRAWN && $withdrawnAt === null) {
            throw new DomainRuleViolation(
                'A withdrawn consent must record when. "We cannot say when they '
                . 'withdrew" is not an answer a regulator accepts.',
                'MISSING_WITHDRAWN_AT'
            );
        }

        if ($withdrawnAt !== null && $withdrawnAt < $grantedAt) {
            throw new DomainRuleViolation(
                'A consent cannot be withdrawn before it was granted.',
                'IMPOSSIBLE_CONSENT_TIMELINE'
            );
        }
    }

    public static function grant(
        string $type,
        \DateTimeImmutable $at,
        ?int $userId = null,
        ?string $anonymousId = null,
        ?string $policyVersion = null,
    ): self {
        return new self($type, self::GRANTED, $at, $userId, $anonymousId, $policyVersion);
    }

    public function isGranted(): bool
    {
        return $this->status === self::GRANTED;
    }

    /** Consent for one purpose is not consent for another. */
    public function covers(string $type): bool
    {
        return $this->type === $type && $this->isGranted();
    }

    /**
     * Withdrawal is terminal for THIS row: a later grant is a new row, which the
     * schema permits (no unique key on subject + type) and which keeps the
     * history intact instead of overwriting it.
     */
    public function withdraw(\DateTimeImmutable $at): self
    {
        if (! $this->isGranted()) {
            throw new DomainRuleViolation(
                sprintf('This consent is %s; it cannot be withdrawn again.', $this->status),
                'CONSENT_NOT_WITHDRAWABLE'
            );
        }

        return new self(
            type: $this->type,
            status: self::WITHDRAWN,
            grantedAt: $this->grantedAt,
            userId: $this->userId,
            anonymousId: $this->anonymousId,
            policyVersion: $this->policyVersion,
            withdrawnAt: $at,
        );
    }
}
