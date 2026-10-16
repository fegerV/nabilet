<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Nabilet\Modules\Notifications\Services\NewsletterSubscriptionService;

/**
 * Публичная подписка на рассылку афиши и отписка по токену.
 *
 * ПОЧЕМУ БЕЗ auth:api
 *   Подписаться — действие гостя до всякого аккаунта: форма живёт на витрине.
 *   Отписка — тем более: владелец адреса обязан уметь отписаться без пароля
 *   (152-ФЗ §9), поэтому адресуется токеном из письма, а не сессией.
 *
 * АНТИ-СПАМ
 *   `throttle:6,1` держит автозабивку мусорных адресов, а XOR-ответы
 *   (`subscribed`/`already`) не вскрывают существование чужих адресов.
 *   Отписка по неизвестному токену — 200 с `unsubscribed: false`: перебор
 *   токенов не подтверждает их валидность.
 */
final class NewsletterController extends Controller
{
    public function __construct(private readonly NewsletterSubscriptionService $subscriptions)
    {
    }

    /** POST /api/v1/subscribe { email }. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'bail', 'email:rfc', 'max:255'],
        ]);

        $subscriber = $this->subscriptions->subscribe((string) $validated['email']);

        return response()->json([
            'data' => [
                'email' => $subscriber->email,
                'status' => $subscriber->status,
            ],
        ], 201);
    }

    /** GET/POST /api/v1/unsubscribe/{token} — отписка без пароля. */
    public function unsubscribe(string $token): JsonResponse
    {
        $known = $this->subscriptions->unsubscribeByToken($token);

        return response()->json([
            'data' => [
                'unsubscribed' => $known,
            ],
        ]);
    }
}
