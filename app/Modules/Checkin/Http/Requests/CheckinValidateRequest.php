<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/checkin/validate` — тело по `openapi.yaml` `CheckinValidateRequest`:
 * `qr_payload` + `session_id`, оба обязательны.
 *
 * ОТСУТСТВИЕ `ticket_id` — НЕ УПРОЩЕНИЕ
 *   В старом `/tickets/checkin/scan` можно было прислать `ticket_id` вместо QR.
 *   Здесь билет определяется ТОЛЬКО подписанным `qr_payload`: валидация без
 *   предъявления кода означает проверку перебираемого целочисленного id, то есть
 *   дыру A11.2 в новом виде. Контракт такое поле не предусматривает.
 *
 * ПОЧЕМУ `session_id` НЕ ПРОВЕРЯЕТСЯ ЗДЕСЬ
 *   Он принимается и как число, и как `public_id` (см. `SessionLocator`), и оба
 *   варианта resolve'ятся одним вызовом в контроллере. Дублировать его в правилах
 *   значило бы спрашивать базу дважды и держать два разных сообщения об ошибке.
 */
final class CheckinValidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Идентификация — на middleware `AuthenticateChecker`; доступ к действию
        // она и закрывает. Второй уровень авторизации здесь был бы вторым
        // мнением о том же самом.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'qr_payload' => ['required', 'string', 'max:1024'],
            'session_id' => ['required'],
        ];
    }
}
