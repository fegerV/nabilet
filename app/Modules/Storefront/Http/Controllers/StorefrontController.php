<?php

declare(strict_types=1);

namespace Nabilet\Modules\Storefront\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Storefront\Services\StorefrontService;
use Throwable;

/**
 * Конструктор витрины.
 *
 * Публичный `GET /storefront` открыт: афишу видит гость, для которого ещё не
 * существует организации. Запись — только под админской ролью и только в
 * конфиг своей организации (мультитенантность ТЗ §10): чужой конфиг по
 * подставленному organization_id прочитать нельзя, идентификатор берётся из
 * токена, а не из тела запроса.
 */
class StorefrontController
{
    public function __construct(
        private readonly StorefrontService $storefront,
        private readonly OrganizationContext $context,
    ) {}

    /** Публичный конфиг витрины (гость). */
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->storefront->resolve($this->publicOrganizationId()),
        ]);
    }

    /**
     * Чью витрину показывать гостю.
     *
     * White-label поддомен даёт организацию сразу. Если его нет (типовая
     * установка «одна площадка на инсталляцию»), берём первой организатора:
     * иначе администратор настроил витрину в админке, а покупатель видит
     * дефолт — расхождение, которое читается как «настройки не сохранились».
     */
    private function publicOrganizationId(): ?string
    {
        $contextId = $this->context->tryId();
        if ($contextId !== null) {
            return $contextId;
        }

        $primary = \Nabilet\Modules\Core\Organizations\Models\Organization::query()
            ->orderBy('id')
            ->value('id');

        return $primary === null ? null : (string) $primary;
    }

    /** Справочник конструктора: виджеты, палитры, дефолты. Нужен форме админки. */
    public function schema(): JsonResponse
    {
        return response()->json(['data' => $this->storefront->schema()]);
    }

    /** Конфиг для редактирования (админ). */
    public function edit(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        return response()->json([
            'data' => $this->storefront->resolve($organizationId),
            'meta' => [
                'organization_id' => $organizationId,
                'is_default' => $this->storefront->isDefault($organizationId),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $payload = $request->input('config', $request->input());
        if (! is_array($payload)) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Ожидался объект настроек витрины.',
                ],
            ], 422);
        }

        try {
            $config = $this->storefront->save(
                $organizationId,
                $payload,
                $request->user()?->id,
            );
        } catch (Throwable) {
            // Конфиг — пользовательский ввод; ошибка записи не должна ронять
            // админку стек-трейсом. Сообщение остаётся общим (§66).
            return response()->json([
                'error' => [
                    'code' => 'STOREFRONT_SAVE_FAILED',
                    'message' => 'Не удалось сохранить настройки витрины. Попробуйте ещё раз.',
                ],
            ], 500);
        }

        return response()->json([
            'data' => $config,
            'meta' => [
                'organization_id' => $organizationId,
                'is_default' => false,
            ],
        ]);
    }

    /** Вернуть витрину к конфигу по умолчанию. */
    public function reset(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $config = $this->storefront->reset($organizationId);

        return response()->json([
            'data' => $config,
            'meta' => [
                'organization_id' => $organizationId,
                'is_default' => true,
            ],
        ]);
    }

    /**
     * Организация берётся из токена, никогда из тела запроса: иначе владелец
     * одной площадки перезаписал бы витрину другой.
     */
    private function organizationId(Request $request): ?string
    {
        $user = $request->user();
        $organizationId = $user?->organizations()->first()?->id;

        if ($organizationId !== null) {
            return (string) $organizationId;
        }

        return $this->context->tryId();
    }
}
