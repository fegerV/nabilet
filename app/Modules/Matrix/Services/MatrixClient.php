<?php

declare(strict_types=1);

namespace Nabilet\Modules\Matrix\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Matrix\Domain\MatrixHomeserver;
use Nabilet\Modules\Matrix\Domain\MatrixMessage;
use Nabilet\Modules\Matrix\Domain\MatrixRoomId;

/**
 * Транспорт в Matrix через Client-Server API Synapse.
 *
 * ПОЧЕМУ PUT, А НЕ POST
 *   Отправка сообщения в комнату в Matrix идемпотентна по построению:
 *   `PUT /_matrix/client/v3/rooms/{roomId}/send/{eventType}/{txnId}`. `txnId` —
 *   идентификатор транзакции от клиента; повтор с тем же `txnId` не создаёт
 *   второе сообщение, а возвращает результат первой попытки. Это ровно то, что
 *   нужно джобе с ретраями: если ответ Synapse потерялся в сети, повтор не
 *   задублирует уведомление в комнате. `POST` такой гарантии не даёт.
 *
 * ПОЧЕМУ ТОКЕН — ЗАГОЛОВОК, А НЕ ПАРАМЕТР
 *   Synapse принимает и `?access_token=`, но параметры URL попадают в логи
 *   прокси, историю и Referer. `Authorization: Bearer` (его ставит
 *   `Http::withToken`) — единственная форма, которая не утекает в логи
 *   промежуточных узлов.
 *
 * ПОЧЕМУ КОМНАТА КОДИРУЕТСЯ
 *   Идентификатор комнаты начинается с `!` и содержит `:` — в пути URL это
 *   недопустимые символы. Без `rawurlencode` запрос ушёл бы с `!room:server`
 *   как есть, и Synapse увидел бы не тот маршрут.
 *
 * ОШИБКИ — ДОМЕННЫЕ, А НЕ «ЧТО-ТО ПОШЛО НЕ ТАК»
 *   Неверная настройка и отказ Synapse — разные исходы, и администратору нужно
 *   их различать в тест-отправке. Оба — `DomainRuleViolation` (HTTP 422 в
 *   API-конверте §66) с разными `code`.
 */
final class MatrixClient
{
    public const TIMEOUT_SECONDS = 10;

    /** Чужой ответ целиком не нужен: он идёт в текст ошибки для админки. */
    private const RESPONSE_BODY_LIMIT = 300;

    public function __construct(private readonly MatrixSettings $settings) {}

    /**
     * Отправить текстовое сообщение в комнату.
     *
     * @param  string|null  $roomId  комната-переопределение; по умолчанию — из настроек
     *
     * @throws DomainRuleViolation канал не настроен, идентификатор комнаты негоден
     *                             или Synapse ответил ошибкой
     */
    public function send(string $body, ?string $roomId = null): void
    {
        $room = MatrixRoomId::normalize($roomId ?? $this->settings->roomId());
        $homeserver = MatrixHomeserver::normalize($this->settings->homeserverUrl());
        $token = $this->settings->accessToken();

        if ($homeserver === null || $room === '' || $token === '') {
            throw new DomainRuleViolation(
                'Канал Matrix не настроен: укажите адрес homeserver, идентификатор комнаты и токен доступа в админке.',
                'MATRIX_NOT_CONFIGURED',
            );
        }

        if (! MatrixRoomId::isValid($room)) {
            throw new DomainRuleViolation(
                sprintf(
                    'Идентификатор комнаты Matrix «%s» некорректен: ожидается вид «!room:server», '
                    . 'а не алиас «#name:server». Скопируйте ID комнаты из Element X.',
                    $room,
                ),
                'MATRIX_INVALID_ROOM',
            );
        }

        // txnId уникален на попытку отправки: повтор ДЖОБЫ (после сетевой ошибки)
        // получит новый txnId и создаст новое сообщение — это ожидаемо, потому
        // что прошлое могло не дойти. Идемпотентность нужна внутри одной попытки,
        // когда HTTP-клиент повторяет запрос сам.
        $url = $homeserver
            . '/_matrix/client/v3/rooms/' . rawurlencode($room)
            . '/send/m.room.message/' . rawurlencode((string) Str::uuid());

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->put($url, MatrixMessage::payload($body));

        if ($response->failed()) {
            throw new DomainRuleViolation(
                sprintf(
                    'Synapse ответил %d на отправку сообщения: %s',
                    $response->status(),
                    Str::limit((string) $response->body(), self::RESPONSE_BODY_LIMIT, ''),
                ),
                'MATRIX_SEND_FAILED',
            );
        }
    }
}
