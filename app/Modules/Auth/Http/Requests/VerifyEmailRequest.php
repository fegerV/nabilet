<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/auth/verify-email`.
 *
 * The token is carried in the body rather than the URL: a query string lands in
 * access logs, `Referer` headers and browser history, and this token is proof of
 * mailbox ownership for up to `AccountTokenService::DEFAULT_TTL` seconds.
 */
class VerifyEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }
}
