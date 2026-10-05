<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Limits mirror `nabilet_core_spec`: `users.first_name`/`last_name` are
     * `VARCHAR(100)`. The earlier `max:255` allowed a 101-character name past
     * validation and into a MySQL 1406 ("Data too long"), which surfaced as a
     * confusing 422 rather than the field-level rejection it should be.
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:12', 'confirmed'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
