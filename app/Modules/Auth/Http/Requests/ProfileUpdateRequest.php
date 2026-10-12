<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/v1/me` — правка собственного профиля.
 *
 * ЗАЧЕМ ОТДЕЛЬНАЯ ФОРМА
 *   Схема `ProfileUpdateRequest` в контракте перечисляет ровно эти шесть полей,
 *   и ни одного из них нельзя взять из админского `UserController::update()`:
 *   там `status` и `password` — то, чего покупатель не меняет себе сам. Общая
 *   форма означала бы, что покупатель через `/me` может выдать себе `status`
 *   или чужую роль.
 *
 * ВСЕ ПОЛЯ `sometimes`: `PATCH` — частичное обновление. Форма, отправляющая
 * только фамилию, не обязана присылать e-mail, и наоборот.
 *
 * `email` — БЕЗ `unique`. Проверку занятости делает `UserService::updateUser()`
 * той же ошибкой поля, что и админский путь (см. `UserService::createUser`), и
 * делает её последней перед записью — то есть видит строку, вставленную
 * параллельным запросом, чего правило `unique` не умеет.
 *
 * `max:100` у имён и `max:255` у адреса — не украшение: это ширина колонок
 * (`users.first_name`/`last_name` — `VARCHAR(100)`, `email` — `VARCHAR(255)`).
 * Без них значение уходило в MySQL и возвращалось ошибкой 1406 «Data too long»
 * как 422 без указания поля.
 */
class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:10'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
