<?php

declare(strict_types=1);

namespace Nabilet\Modules\Matrix\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Nabilet\Modules\Matrix\Services\MatrixClient;

/**
 * Отправить одно сообщение в комнату Matrix.
 *
 * ПОЧЕМУ ДЖОБА, А НЕ ПРЯМОЙ ВЫЗОВ ИЗ ОБСЕРВЕРА
 *   Обсервер оплаты срабатывает ВНУТРИ транзакции, которая записывает статус
 *   заказа. Сетевой вызов оттуда держал бы транзакцию открытой на время HTTP-
 *   запроса к чужому серверу (до 10 секунд), а падение Synapse откатило бы
 *   оплату — то есть «уведомление не ушло» превращалось бы в «деньги не
 *   приняты». Джоба ставится с `afterCommit()`, поэтому уходит только после
 *   фиксации заказа, и её провал не трогает платёж.
 *
 * ПОЧЕМУ ТЕЛО ГОТОВИТСЯ ЗАРАНЕЕ
 *   В джобу передаётся уже собранный текст, а не модель заказа. Так джоба не
 *   сериализует Eloquent-модель (а значит, не зависит от состояния БД на момент
 *   выполнения), и текст можно проверить в обсервере, не поднимая очередь.
 *
 * ПОЧЕМУ `tries = 3`
 *   Отказ Synapse обычно временный (перезапуск, сеть). Три попытки с
 *   нарастающей паузой переживают короткий сбой, но не превращаются в
 *   бесконечный ретрай навсегда неверной настройки. Неверная настройка даёт
 *   `MATRIX_NOT_CONFIGURED`/`MATRIX_INVALID_ROOM` — это не лечится повтором, и
 *   такие попытки исчерпаются быстро.
 */
final class SendMatrixMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> паузы между попытками, секунды */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly string $body) {}

    public function handle(MatrixClient $client): void
    {
        $client->send($this->body);
    }
}
