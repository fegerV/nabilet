<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Единственное представление схемы `User` контракта.
 *
 * ЗАЧЕМ ОДИН КЛАСС НА ТРИ ОТВЕТА
 *   `User` объявлена в `nabilet_core_spec/openapi.yaml` один раз и возвращается
 *   из `POST /auth/register`, `POST /auth/login`, `POST /auth/email/verify`,
 *   `GET /me` и `PATCH /me` (`UserResponse.data`). Пока `GET /me` не
 *   существовал, расхождение было невидимым: поля собирались ровно там, где
 *   ответ рождался. Как только `/me` появился, две копии одного представления
 *   означали бы, что «профиль» и «пользователь из логина» — разные объекты с
 *   разными полями. Поэтому форма описана здесь, а `/me` переиспользует её
 *   (`MeController`), а не заводит второй ресурс.
 *
 * ЧТО БЫЛО НЕ ТАК ДО ЭТОЙ ПРАВКИ
 *   Три поля, обязательных по схеме `User`, отсутствовали: `status`, `locale`,
 *   `timezone` (все `required`). А `id` отдавался внутренним BIGINT, тогда как
 *   схема объявляет `type: string`, и весь остальной API адресует пользователя
 *   по `users.public_id` (`UserController::show($publicId)`, параметр
 *   `PublicIdPath`). То есть вход возвращал числовой идентификатор, которым
 *   нельзя было воспользоваться ни на одном другом маршруте.
 *
 *   Публичный идентификатор — `public_id` (CHAR(26), ULID base32). Внутренний
 *   `id` наружу не выходит нигде (`DATABASE.md` §9), и здесь он тоже не выходит.
 *
 * `roles` в схеме не объявлены, но и не запрещены (`additionalProperties` не
 * выставлен). Они нужны оболочке админки: `isAdminSession()` решает по
 * `user.roles`, пускать ли в панель, и это решение принимается ДО любого
 * серверного запроса — то есть роли обязаны прийти вместе с входом.
 */
class AuthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'status' => $this->status,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'roles' => $this->relationLoaded('roles')
                ? $this->roles->pluck('slug')->values()
                : [],
        ];
    }
}
