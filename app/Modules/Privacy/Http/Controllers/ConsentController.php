<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Nabilet\Modules\Privacy\Models\Consent;
use Nabilet\Modules\Privacy\Services\ConsentService;

/**
 * Согласия субъекта-аккаунта: список и отзыв (152-ФЗ §9).
 *
 * ЗАЧЕМ СПИСОК
 *   Право субъекта видеть, на что он когда-то дал согласие и что из этого уже
 *   забрал обратно, — часть самого мандата согласия: согласие, которое нельзя
 *   пересмотреть, не считается добровольным. Список отдаёт по ОДНОЙ ПОСЛЕДНЕЙ
 *   строке на каждую цель (не всю историю): витрина показывает состояние, а не
 *   журнал; полный журнал остаётся в таблице `consents` (статусная история —
 *   grant → withdrawn — видна по строкам, и её прочтение держит правило
 *   «последнее решает» в домене).
 *
 * ЗАЧЕМ ОТЗЫВ
 *   Забрать согласие — та же рука, которой его давали: владелец аккаунта
 *   отзывается под своим `auth:api`, без писем и токенов. Отзыв — событие
 *   домена (`ConsentService`), контроллер только раскладывает HTTP.
 *
 * ЧЕГО ЗДЕСЬ НЕТ
 *   Generic grant (self-service «выдать себе согласие») не построен
 *   сознательно — решение описано в докблоке `ConsentService`. Анонимные
 *   согласия (рассылка: `anonymous_id`-субъект, отписка по токену) в списке
 *   не видны: их принадлежность держится на совпадении адреса, а не на
 *   аутентификации, и выдавать их за «данные аккаунта» нельзя.
 */
final class ConsentController extends Controller
{
    public function __construct(private readonly ConsentService $consents)
    {
    }

    /** GET /api/v1/me/consents — согласия владельца Bearer-токена. */
    public function index(Request $request): JsonResponse
    {
        /** @var \Nabilet\Modules\Core\Users\Models\User $user */
        $user = $request->user('api');

        $latest = $this->consents->latestByType($user);

        ksort($latest);

        // `array_map` сохраняет строковые ключи (ответ стал бы объектом вместо
        // массива, а витрина сравнивает по списку), поэтому ключи сброшены явно.
        $data = array_values(array_map($this->present(...), $latest));

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/v1/me/consents/{type}/withdraw — отозвать согласие.
     *
     * Уже отозванное возвращает 200 с тем же состоянием и `written: false` —
     * витрине не нужно различать «отозвал сейчас» и «уже было отозвано»:
     * цель запроса «согласие не активно» достигнута в обоих случаях.
     */
    public function withdraw(Request $request, string $type): JsonResponse
    {
        /** @var \Nabilet\Modules\Core\Users\Models\User $user */
        $user = $request->user('api');

        // Цель в пути, не в теле (и валидируется сам параметр маршрута,
        // а не поле ввода): отзовик адресует конкретное согласие, а не
        // «какое-нибудь». Словарь целей открытый (к ним добавляют новые
        // потребители обработки) — значит, проверяем формат, а не список:
        // латиница/цифры/подчёркивания/дефис, до 64 знаков как в базе.
        Validator::make(
            ['type' => $type],
            ['type' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/']],
            ['type.regex' => 'Неверный идентификатор согласия.'],
        )->validate();

        $result = $this->consents->withdraw(
            $user,
            $type,
            ip: $request->ip(),
            ua: $request->userAgent(),
        );

        return response()->json([
            'data' => $this->present($result['consent']) + ['written' => $result['written']],
        ]);
    }

    /**
     * Форма ответа субъекту: состояние согласия. `created_at` — момент
     * ФИКСАЦИИ строки: у последней granted-строки это момент выдачи, у
     * withdrawn-строки — момент отзыва (строка отзыва создаётся с
     * `created_at == withdrawn_at`); `withdrawn_at: null` значит
     * «согласие стоит». Даты в ISO-8601, как у остальных сериализаций.
     *
     * @return array<string, mixed>
     */
    private function present(Consent $consent): array
    {
        return [
            'consent_type' => $consent->consent_type,
            'status' => $consent->status,
            'created_at' => $consent->created_at->toIso8601String(),
            'withdrawn_at' => $consent->withdrawn_at?->toIso8601String(),
            'policy_version' => $consent->policy_version,
        ];
    }
}
