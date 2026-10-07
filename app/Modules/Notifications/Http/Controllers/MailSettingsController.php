<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Modules\Notifications\Services\MailSettings;

/**
 * Настройки исходящей почты в админке.
 *
 * Только администратор: здесь лежит SMTP-доступ, а через него — возможность
 * рассылать письма от имени магазина.
 */
class MailSettingsController extends Controller
{
    public function __construct(private readonly MailSettings $settings) {}

    /** GET /api/v1/admin/mail/settings */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    /** PUT /api/v1/admin/mail/settings */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Пустая строка = «не менять пароль», поэтому nullable без min.
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'encryption' => ['sometimes', 'nullable', 'in:tls,ssl,none'],
            'from_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'clear_password' => ['sometimes', 'boolean'],
        ]);

        $clearPassword = (bool) ($data['clear_password'] ?? false);
        unset($data['clear_password']);

        // `none` — осознанное «без шифрования»: в MailSettings оно маппится в
        // отсутствие scheme. Сохраняем как пустую строку, чтобы перекрыть .env.
        if (array_key_exists('encryption', $data)) {
            $data['encryption'] = $data['encryption'] === 'none' ? '' : $data['encryption'];
        }

        $this->settings->save($data, $clearPassword);

        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    /**
     * POST /api/v1/admin/mail/test — отправить тестовое письмо.
     *
     * 200 с `ok: false` вместо 4xx/5xx: неверный SMTP-пароль при настройке —
     * ожидаемый результат, и админке нужен текст ошибки, а не голый код.
     */
    public function sendTest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'email', 'max:255'],
        ]);

        $error = $this->settings->sendTest($data['recipient']);

        return response()->json([
            'data' => [
                'ok' => $error === null,
                'error' => $error,
            ],
        ]);
    }
}
