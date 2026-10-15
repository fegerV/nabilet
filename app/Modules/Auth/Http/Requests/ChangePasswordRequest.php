<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Nabilet\Modules\Auth\Validation\PasswordRules;

/**
 * `POST /api/v1/me/password` — смена пароля из личного кабинета.
 *
 * `current_password` ОБЯЗАТЕЛЕН НЕ ВСЕГДА, и это расхождение со спекой —
 * осознанное. Схема объявляет `required: [current_password, password,
 * password_confirmation]` и исходит из того, что у аккаунта пароль уже есть.
 * С появлением аккаунта, заведённого по факту покупки (см.
 * `CustomerAccountService`), это перестало быть верным: у такого покупателя
 * `users.password IS NULL`, и требование «текущего пароля» делает эндпоинт
 * недостижимым — предъявить нечего, `Hash::check()` с пустым хешем всегда false.
 *
 * Поэтому правило условное: текущий пароль требуется ТОЛЬКО у аккаунта, у
 * которого пароль уже есть. Подтверждать владение для аккаунта без пароля нечем
 * и не нужно — запрос уже аутентифицирован Bearer-токеном из `user_sessions`,
 * то есть предъявлено ровно то же доказательство, что и при любом другом вызове
 * под `auth:api`.
 *
 * Требование к НОВОМУ паролю берётся из `PasswordRules` — той же политики, что
 * на регистрации и в админке. Второй копии `min:12` здесь не будет: три копии
 * одного правила расходятся именно так (см. шапку `PasswordRules`).
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $hasPassword = $user !== null
            && $user->password !== null
            && $user->password !== '';

        return [
            'current_password' => $hasPassword
                ? ['required', 'string']
                : ['nullable', 'string'],
            'password' => array_merge(PasswordRules::required(), ['confirmed']),
        ];
    }
}
