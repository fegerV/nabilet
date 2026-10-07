<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/auth/forgot-password`.
 *
 * The address is only *shaped* like an e-mail here — it is not required to exist.
 * "No such user" and "mail sent" must be indistinguishable to the caller, or the
 * endpoint becomes an account-enumeration oracle; the controller answers 204
 * either way. `exists:users,email` would undo that in one line.
 */
class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
