<?php

declare(strict_types=1);

namespace Nabilet\Modules\Analytics\Http\Controllers;

use Nabilet\Modules\Analytics\Services\MetrikaSettings;

/**
 * Публичный эндпоинт конфигурации Яндекс Метрики для SPA.
 *
 * Фронтенд (resources/js/lib/metrika.ts) тянет отсюда ID счётчика и имена
 * целей, чтобы не хардкодить их в сборке: админ меняет настройки на сервере,
 * витрина подхватывает без редеплоя JS.
 */
class MetrikaController
{
    public function __construct(private readonly MetrikaSettings $settings)
    {
    }

    /** GET /api/v1/analytics/metrika/config — конфигурация счётчика для SPA. */
    public function config(): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->settings->publicConfig());
    }
}
