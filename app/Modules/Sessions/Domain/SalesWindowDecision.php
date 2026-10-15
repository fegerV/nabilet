<?php

declare(strict_types=1);

namespace Nabilet\Modules\Sessions\Domain;

/**
 * Итог оценки окна продаж: продаём или нет, и если нет — почему.
 *
 * Отдельный объект, а не bool + строка из двух вызовов: модель отдаёт наружу
 * ОДИН ответ гейта (`isSellableAt`) и текст причины (`salesBlockReason`), и
 * разъехаться они не должны. Считали «продаём» — текст обязан быть пустым;
 * отказали — текст обязателен.
 */
final class SalesWindowDecision
{
    /** Словарь отказов — машинночитаемые коды причин. */
    public const BLOCKED_STATUS = 'not_sellable_status';
    public const BLOCKED_BEFORE_OPEN = 'before_open';
    public const BLOCKED_BEFORE_EARLY_ACCESS = 'before_early_access_open';
    public const BLOCKED_AFTER_CLOSE = 'after_close';

    private function __construct(
        public readonly bool $sellable,
        public readonly string $verdict,
        public readonly string $reason = '',
    ) {
    }

    public static function sellable(): self
    {
        return new self(true, 'sellable');
    }

    public static function blocked(string $verdict, string $reason): self
    {
        return new self(false, $verdict, $reason);
    }
}
