<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Nabilet\Modules\Auth\Validation\PasswordRules;

/**
 * `POST /api/v1/auth/reset-password`.
 *
 * Shape mirrors `docs/openapi.yaml`: `token`, `email`, `password`,
 * `password_confirmation`.
 *
 * Пароль — из `PasswordRules`, а не строкой `min:12`. Строка объявляла только
 * длину и была ЧЕТВЁРТОЙ копией правила: сброс пароля стал бы обходом политики —
 * аккаунт, зарегистрированный со сложным паролем, «восстанавливался» до
 * `qwertyuiopas`. Политика обязана быть одна на все точки, где пароль задаётся.
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => array_merge(PasswordRules::required(), ['confirmed']),
        ];
    }
}
