<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Support;

use Nabilet\Modules\Tickets\Domain\ScanOutcome;

/**
 * Словарь результата по контракту (`openapi.yaml` → `CheckinResult.result`) и
 * перевод в него доменных исходов.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС, ЕСЛИ ЕСТЬ `ScanOutcome`
 *   `ScanOutcome` — внутренний словарь: он пишется в `ticket_scans.result`
 *   дословно, и его читают отчёты. Контракт же объявляет другой набор: у него
 *   есть `invalid_signature`, `unknown_ticket` и `conflict`, и нет ни
 *   `admitted`, ни `not_found`, ни `refused_by_device`. Два списка сосуществуют
 *   by design: в базе — что произошло на самом деле, на проводе — что об этом
 *   обещано клиенту. Слияние их в один класс означало бы либо переименование
 *   колонки (миграция данных), либо поломку контракта.
 *
 * ПОЧЕМУ `revoked` → `conflict`, А НЕ `valid`/`already_used`
 *   В контракте нет `revoked`, а отозванный билет на входе — это инцидент:
 *   человек предъявляет билет, который отозван. Ближайший честный код — конфликт.
 *
 * `not_found` → `unknown_ticket`. `conflict_revoked` и `refused_by_device` тоже
 * дают `conflict`: в первом случае невозможно отменить уже совершённый вход
 * (§44), во втором устройство отказало валидному билету — оба случая требуют
 * разбирательства, а не ответа на входе.
 */
final class CheckinVerdict
{
    public const VALID = 'valid';
    public const ALREADY_USED = 'already_used';
    public const CANCELLED = 'cancelled';
    public const REFUNDED = 'refunded';
    public const EXPIRED = 'expired';
    public const INVALID_SIGNATURE = 'invalid_signature';
    public const WRONG_SESSION = 'wrong_session';
    public const WRONG_EVENT = 'wrong_event';
    public const UNKNOWN_TICKET = 'unknown_ticket';
    public const CONFLICT = 'conflict';

    /**
     * Домены, из которых получается `wrong_event`: решение зависит не только от
     * исхода, но и от того, разные ли события у сеанса сканирования и билета.
     */
    private const WRONG_SESSION_OUTCOME = ScanOutcome::WRONG_SESSION;

    /** @var array<string, string> доменный исход → код контракта. */
    private const MAP = [
        ScanOutcome::ADMITTED => self::VALID,
        ScanOutcome::ALREADY_USED => self::ALREADY_USED,
        ScanOutcome::CANCELLED => self::CANCELLED,
        ScanOutcome::REFUNDED => self::REFUNDED,
        ScanOutcome::EXPIRED => self::EXPIRED,
        ScanOutcome::REVOKED => self::CONFLICT,
        ScanOutcome::WRONG_SESSION => self::WRONG_SESSION,
        ScanOutcome::NOT_FOUND => self::UNKNOWN_TICKET,
        ScanOutcome::CONFLICT_REVOKED => self::CONFLICT,
        ScanOutcome::REFUSED_BY_DEVICE => self::CONFLICT,
    ];

    /**
     * HTTP-статус по коду контракта: `use` отвечает 200 на отказ на входе
     * (оператор должен увидеть причину, а не «ошибку сети»), но 409 — когда
     * билет уже погашен или зафиксирован конфликт (`openapi.yaml`: 409
     * «Already used/conflict»). Всё остальное — 200: подделаный QR и отменённый
     * билет это ответ на вопрос «можно ли пустить», а не сбой запроса.
     */
    public static function httpStatus(string $verdict): int
    {
        return in_array($verdict, [self::ALREADY_USED, self::CONFLICT], true) ? 409 : 200;
    }

    public static function fromOutcome(string $outcome, bool $sameEvent = true): string
    {
        if ($outcome === self::WRONG_SESSION_OUTCOME && ! $sameEvent) {
            return self::WRONG_EVENT;
        }

        return self::MAP[$outcome] ?? self::UNKNOWN_TICKET;
    }

    /**
     * Человекочитаемый текст для экрана устройства. Приоритет у причины из
     * домена: «this ticket was revoked: возврат по Chargeback R-19» информативнее
     * общего «Отозван».
     */
    public static function messageFor(string $verdict, ?string $reason = null): string
    {
        if (is_string($reason) && $reason !== '' && ! self::isMachineReason($reason)) {
            return $reason;
        }

        return match ($verdict) {
            self::VALID => 'Билет действителен.',
            self::ALREADY_USED => 'Билет уже погашен.',
            self::CANCELLED => 'Билет отменён.',
            self::REFUNDED => 'Билет возвращён.',
            self::EXPIRED => 'Срок действия билета истёк.',
            self::INVALID_SIGNATURE => 'QR-код недействителен.',
            self::WRONG_SESSION => 'Билет на другой сеанс.',
            self::WRONG_EVENT => 'Билет на другое мероприятие.',
            self::UNKNOWN_TICKET => 'Билет не найден.',
            default => 'Конфликт: требуется проверка оператором.',
        };
    }

    /**
     * Причина вида `WRONG_SESSION` (SCREAMING_SNAKE) — это машинный код, он не
     * должен попадать на экран оператора как есть.
     */
    private static function isMachineReason(string $reason): bool
    {
        return $reason === strtoupper($reason) && preg_match('/[A-Z]/', $reason) === 1;
    }
}
