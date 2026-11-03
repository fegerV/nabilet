<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Modules\Privacy\Domain\PrivacyRequest;
use Nabilet\Modules\Privacy\Models\PrivacyRequest as PrivacyRequestModel;
use Nabilet\Modules\Privacy\Services\PrivacyRequestService;

/**
 * Подача и отслеживание обращений субъекта ПДн (152-ФЗ: доступ, исправление,
 * удаление, ограничение, переносимость).
 *
 * ПОЧЕМУ БЕЗ auth:api
 *   Право на обращение есть у каждого субъекта данных, а не только у
 *   владельца аккаунта: человек, купивший билет гостем (только e-mail),
 *   вправе попросить его удаление, не заводя профиль. Поэтому гость обязан
 *   дать контактный адрес `email`; авторизованный — нет: адрес берётся
 *   с фолбэком из профиля. Дальше адрес живёт в `payload_json.contact_email` —
 *   единственном месте, где он нужен после подачи (канал связи с субъектом).
 *
 * ЧТО НЕ ОТДАЁТСЯ
 *   `payload_json` здесь отсутствует намеренно: это контактный адрес и текст
 *   обращения — ровно то, что не должен прочесть посторонний, узнавший
 *   public_id из перехваченной ссылки. Админ видит payload через
 *   `AdminPrivacyRequestController`.
 *
 * АНТИ-СКАНИРОВАНИЕ
 *   Неизвестный public_id — 404 без деталей существует/нет; ответ не
 *   вскрывает чужие обращения (capabilities, а не перечисление). Отсечение
 *   частоты — `throttle` на маршрутах.
 */
final class PrivacyRequestController extends Controller
{
    public function __construct(private readonly PrivacyRequestService $requests)
    {
    }

    /** POST /api/v1/privacy-requests — подать обращение. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user('api');

        $validated = $request->validate([
            // 152-ФЗ знает пять видов обращений; словарь — домен
            // (`PrivacyRequest::types()`), он же CHECK в базе.
            'type' => ['required', 'bail', Rule::in(PrivacyRequest::types())],

            // Гость обязан оставить контакт (иначе ответить некому),
            // авторизованный — нет: адрес уже есть в профиле.
            'email' => [$user !== null ? 'nullable' : 'required', 'bail', 'email:rfc', 'max:255'],

            // Свободный текст — «что именно вы хотите узнать/исправить».
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $model = $this->requests->submit(
            $user,
            (string) $validated['type'],
            // Фолбэк на адрес профиля: оператор обязан иметь канал связи для
            // ЛЮБОГО обращения, а не только для гостевого.
            $validated['email'] ?? $user?->email,
            isset($validated['message']) ? (string) $validated['message'] : null,
        );

        return response()->json(['data' => $this->present($model)], 201);
    }

    /** GET /api/v1/privacy-requests/{publicId} — отслеживание своего обращения. */
    public function show(string $publicId): JsonResponse
    {
        $model = $this->requests->findByPublicId($publicId);

        if ($model === null) {
            throw new NotFoundError('PrivacyRequest', $publicId);
        }

        return response()->json(['data' => $this->present($model)]);
    }

    /**
     * Форма ответа для субъекта: статус, дедлайн, момент закрытия — и ничего
     * про содержимое (`payload_json`). Даты в ISO-8601, как у остальных
     * сериализаций проекта; `null` completed_at означает «в работе».
     *
     * @return array<string, mixed>
     */
    private function present(PrivacyRequestModel $model): array
    {
        return [
            'public_id' => $model->public_id,
            'type' => $model->type,
            'status' => $model->status,
            'created_at' => $model->created_at->toIso8601String(),
            'due_at' => $model->due_at->toIso8601String(),
            'completed_at' => $model->completed_at?->toIso8601String(),
        ];
    }
}
