<?php

declare(strict_types=1);

namespace Nabilet\Modules\Matrix\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Matrix\Domain\MatrixHomeserver;
use Nabilet\Modules\Matrix\Domain\MatrixNotice;
use Nabilet\Modules\Matrix\Domain\MatrixRoomId;
use Nabilet\Modules\Matrix\Services\MatrixClient;
use Nabilet\Modules\Matrix\Services\MatrixSettings;
use Throwable;

/**
 * Настройки канала Matrix в админке.
 *
 * Только администратор: здесь лежит токен доступа к Synapse, а через него —
 * возможность писать в комнату от имени бота.
 *
 * ПОЧЕМУ ФОРМАТЫ ПРОВЕРЯЮТСЯ ЗДЕСЬ, А НЕ ТОЛЬКО НА КЛИЕНТЕ
 *   Админка — не единственный вход в API: тот же `PUT` можно послать curl'ом.
 *   Проверка формата на клиенте нужна для удобства, на сервере — для
 *   корректности. Поэтому `homeserver_url` и `room_id` валидируются доменом
 *   (`MatrixHomeserver`, `MatrixRoomId`), а не полагаются на `string|max:255`.
 */
final class MatrixSettingsController extends Controller
{
    public function __construct(
        private readonly MatrixSettings $settings,
        private readonly MatrixClient $client,
    ) {}

    /** GET /api/v1/admin/matrix/settings */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    /** PUT /api/v1/admin/matrix/settings */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'homeserver_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'room_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Пустая строка = «не менять токен», поэтому nullable без min.
            'access_token' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'enabled' => ['sometimes', 'boolean'],
            'notify_orders' => ['sometimes', 'boolean'],
            'clear_access_token' => ['sometimes', 'boolean'],
        ]);

        $clearToken = (bool) ($data['clear_access_token'] ?? false);
        unset($data['clear_access_token']);

        $this->assertFormat($data);

        $this->settings->save($data, $clearToken);

        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    /**
     * POST /api/v1/admin/matrix/test — отправить тестовое сообщение в комнату.
     *
     * 200 с `ok: false` вместо 4xx/5xx: неверный токен или алиас вместо ID
     * комнаты при настройке — ожидаемый результат, и админке нужен текст
     * ошибки, а не голый код. Тот же приём, что у тестового письма SMTP
     * (`MailSettingsController::sendTest`).
     */
    public function sendTest(): JsonResponse
    {
        $error = null;

        try {
            $this->client->send(MatrixNotice::test());
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return response()->json([
            'data' => [
                'ok' => $error === null,
                'error' => $error,
            ],
        ]);
    }

    /**
     * Формат адреса и комнаты — доменными правилами, а не регуляркой по месту.
     *
     * Пустая строка допустима: это «стереть значение» и вернуться к `.env`.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertFormat(array $data): void
    {
        $errors = [];

        if (array_key_exists('homeserver_url', $data)) {
            $homeserver = (string) ($data['homeserver_url'] ?? '');

            if (trim($homeserver) !== '' && ! MatrixHomeserver::isValid($homeserver)) {
                $errors['homeserver_url'] = [
                    'Укажите адрес вида https://matrix.example.org (или matrix.example.org — схема https подставится сама).',
                ];
            }
        }

        if (array_key_exists('room_id', $data)) {
            $room = (string) ($data['room_id'] ?? '');

            if (trim($room) !== '' && ! MatrixRoomId::isValid($room)) {
                $errors['room_id'] = [
                    'Нужен идентификатор комнаты вида !room:server, а не алиас #name:server. Скопируйте ID комнаты из Element X.',
                ];
            }
        }

        if ($errors !== []) {
            throw new ValidationError($errors);
        }
    }
}
