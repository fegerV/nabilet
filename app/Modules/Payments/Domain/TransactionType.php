<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Domain;

use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;

/**
 * Тип записи в журнале `payment_transactions` (ТЗ §27, §28).
 *
 * ПОЧЕМУ ЭТОТ КЛАСС СУЩЕСТВУЕТ.
 * Словарь типа был объявлен ровно в одном месте — в докблоке модели
 * (`PaymentTransaction`: `'authorization' | 'capture' | 'refund' | 'failure'`), —
 * а писали его ГОЛЫМИ СТРОКАМИ в шести местах: пять раз в `PaymentService`
 * (`createPayment`, `handleSucceededEvent`, `recordReceiptAndRefund`,
 * `handleRefundEvent`, `handlePaymentFailed`) и один раз в `RefundService`.
 * Докблок ничего не запрещает: седьмой писатель мог написать `'void'`, и никто
 * бы не заметил — колонка `type VARCHAR(64) NOT NULL` объявлена без CHECK.
 *
 * Спека при этом называла журнал чужим словарём: `payment_transactions.status` в
 * `017_status_vocabularies.sql`, в зеркальной миграции, в `migrations.sql` и в
 * §3.11 ревью помечена как «словарь платёжного провайдера». Это неверно и было
 * неверно всегда: ни один писатель не берёт `type` или `status` из payload —
 * значения пишем МЫ, а сырой ответ провайдера уходит в `payload_json`. Ровно так
 * это и написано комментарием в `PaymentService::handleRefundEvent()`; документы
 * говорили обратное, и потому никому не приходило в голову дать колонкам
 * владельца. Ложная проза хуже отсутствующей: её верят и не перепроверяют.
 *
 * ПОЧЕМУ ПАРА (тип, статус) — ОДНО ПРАВИЛО, А НЕ ДВЕ КОЛОНКИ.
 * Тип записи определяет, какие статусы вообще возможны: авторизация не может
 * быть `succeeded` (деньги ещё не взяты), фиксация не может быть `pending`
 * (деньги уже взяты), неудача не бывает `succeeded`. Это правило не было
 * записано нигде — оно просто совпадало у всех шести писателей, то есть
 * держалось на внимательности. Ошибка здесь не косметическая: журнал — то, чем
 * сверяются с выпиской провайдера, и запись «списание, которое ещё не прошло»
 * делает сверку неверной в сторону «денег нет».
 *
 * ЧЕГО ЗДЕСЬ НЕТ И ПОЧЕМУ.
 * CHECK на `type` не добавлен, хотя словарь закрыт и полностью наш: пакет спеки
 * — источник истины, а закрепление словаря в базе — отдельное решение, которое
 * меняет `migrations.sql` и сплиты (так же, как это было с `ck_carts_status`).
 * Пока правило держит единственный писатель — `PaymentRepository::addTransaction()`,
 * и он вызывает `assertAllowed()` ниже, поэтому обойти правило можно только
 * правкой этого класса.
 */
final class TransactionType
{
    /** Попытка списания: деньги ещё не взяты, платёж в `pending`. */
    public const AUTHORIZATION = 'authorization';

    /** Деньги взяты: единственный тип, который означает поступление. */
    public const CAPTURE = 'capture';

    /** Возврат: пишется дважды — при инициации (`pending`) и по исходу. */
    public const REFUND = 'refund';

    /** Платёж не состоялся: денег не было ни в один момент. */
    public const FAILURE = 'failure';

    /**
     * Полный словарь. Он же — единственное объявление: докблок модели и тесты
     * ссылаются сюда, а не переписывают значения.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::AUTHORIZATION,
            self::CAPTURE,
            self::REFUND,
            self::FAILURE,
        ];
    }

    /**
     * Статусы платежа, которые запись этого типа может утверждать.
     *
     * Значения — константы `PaymentStateMachine`, а не свои строки: журнал
     * описывает состояние ПЛАТЕЖА, и второй словарь тех же состояний разошёлся бы
     * с первым при первом же новом статусе.
     *
     * Пустой список для неизвестного типа — не «всё разрешено», а «ничего»:
     * `allows()` отсекает неизвестный тип раньше, чем сюда заглянет.
     *
     * @return list<string>
     */
    public static function statusesFor(string $type): array
    {
        return match ($type) {
            self::AUTHORIZATION => [PaymentStateMachine::PENDING],
            self::CAPTURE => [PaymentStateMachine::SUCCEEDED],
            self::REFUND => [
                PaymentStateMachine::PENDING,
                PaymentStateMachine::SUCCEEDED,
                PaymentStateMachine::FAILED,
            ],
            self::FAILURE => [PaymentStateMachine::FAILED],
            default => [],
        };
    }

    /**
     * `status` в схеме объявлен NULL-колонкой, поэтому «статус не указан» —
     * легальная запись, а не ошибка: строка журнала может фиксировать только
     * факт события провайдера. Проверяется не наличие статуса, а его
     * согласованность с типом, когда он указан.
     */
    public static function allows(string $type, ?string $status): bool
    {
        if (! in_array($type, self::all(), true)) {
            return false;
        }

        if ($status === null) {
            return true;
        }

        return in_array($status, self::statusesFor($type), true);
    }

    /**
     * Единственная точка, где правило превращается в отказ.
     *
     * Исключение здесь — не про пользовательский ввод и не про payload
     * провайдера: `type` и `status` в журнал приходят только из констант этого
     * приложения, поэтому несогласованная пара означает ошибку в НАШЕМ коде, а
     * не плохие данные извне. Ретраить такое бессмысленно, и вебхук от этого
     * цикла не получит — он не в состоянии подсунуть свой `type`.
     */
    public static function assertAllowed(string $type, ?string $status): void
    {
        if (self::allows($type, $status)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'payment_transactions: тип "%s" не может иметь статус "%s" (допустимо: %s)',
            $type,
            $status ?? 'null',
            implode(', ', self::statusesFor($type)) ?: 'тип не входит в словарь',
        ));
    }
}
