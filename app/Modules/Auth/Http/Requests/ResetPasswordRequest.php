<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/auth/reset-password`.
 *
 * Shape mirrors `docs/openapi.yaml`: `token`, `email`, `password`,
 * `password_confirmation`. The minimum length and the `confirmed` rule are the
 * same ones `RegisterRequest` uses (`min:12`), so a password that could not be
 * set at registration cannot be set here either — otherwise the reset flow would
 * be a way to weaken an existing account.
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
            'password' => ['required', 'min:12', 'confirmed'],
        ];
    }
}
