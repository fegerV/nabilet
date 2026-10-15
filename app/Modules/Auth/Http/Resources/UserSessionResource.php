<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Схема `UserSession` контракта — одна строка списка `GET /api/v1/me/sessions`.
 *
 * ЧТО НЕ ОТДАЁТСЯ, И ПОЧЕМУ ЭТО ГЛАВНОЕ ЗДЕСЬ
 *   `session_token_hash` — не выводится. Колонка хранит sha256 от Bearer-токена,
 *   и именно она превращает дамп `user_sessions` в набор пригодных учётных
 *   данных. Ресурс перечисляет поля явно, поэтому новое поле в таблице не
 *   появится в ответе само собой.
 *
 * `ip_address` тоже не выводится: колонка `VARBINARY(16)` (упакованный
 * IPv4/IPv6, см. `Nabilet\Core\Support\PackedIp`), и её сырое значение в JSON
 * было бы нечитаемым, а декодированное — лишним раскрытием. Схема `UserSession`
 * его и не объявляет.
 *
 * `id` — внутренний `user_sessions.id`, приведённый к строке: у таблицы нет
 * `public_id`, а схема объявляет `id: {type: string}`. Идентификатор не является
 * секретом: строка сессии адресуется только в `DELETE /me/sessions/{session}`,
 * который проверяет владельца, поэтому чужой id не открывает ничего.
 */
class UserSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'device_name' => $this->device_name,
            'user_agent' => $this->user_agent,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
