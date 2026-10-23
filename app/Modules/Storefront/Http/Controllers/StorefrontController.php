<?php

declare(strict_types=1);

namespace Nabilet\Modules\Storefront\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Nabilet\Core\Support\UserOrganization;
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
        // Аудит производительности 1.1: `GET /storefront` выполняется на каждом
        // открытии витрины, а конфиг меняется только из админки. Короткий кеш
        // (60 c) с явной инвалидацией в `update()`/`reset()` — устаревание
        // ограничено и для самой админки неощутимо.
        $organizationId = $this->publicOrganizationId();
        $cacheKey = 'storefront-config:' . (string) $organizationId;

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return response()->json(['data' => $cached]);
        }

        $config = $this->storefront->resolve($organizationId);

        Cache::put($cacheKey, $config, now()->addSeconds(60));

        return response()->json([
            'data' => $config,
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

        Cache::forget('storefront-config:' . (string) $organizationId);

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

        Cache::forget('storefront-config:' . (string) $organizationId);

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
        // Членство — через `UserOrganization`: тот же вопрос задают события,
        // площадки и вебхуки, и ответ обязан быть один. Здесь стоял
        // `->first()` без сортировки, то есть у сотрудника с двумя членствами
        // витрина могла открыться не той организации, которую выбрал
        // middleware в том же запросе.
        $organizationId = UserOrganization::id($request->user());

        if ($organizationId !== null) {
            return (string) $organizationId;
        }

        // Покупатель членства не имеет — тогда решает контекст middleware'а
        // (`ResolveOrganizationContext`), у которого есть ещё и хост.
        return $this->context->tryId();
    }
}
