<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nabilet\Modules\System\Services\HealthProbe;

/**
 * `GET /api/v1/health` — единственная точка, по которой внешний наблюдатель
 * (load balancer, uptime-робот, k8s probe) может судить о работоспособности
 * установки. Контракт объявлен в спеке: `nabilet_core_spec/openapi.yaml:1292`
 * (`HealthResponse`, ответы 200 и 503), продублирован в `docs/openapi.yaml`.
 *
 * ПОЧЕМУ ЭТОТ КЛАСС ОТДЕЛЬНЫЙ, А НЕ МЕТОД В SystemStatusController
 *   У них разные аудитории и разные требования к утечкам.
 *
 *   `SystemStatusController` — админская диагностика: версии PHP и Laravel,
 *   геометрия диска, содержимое логов. Всё это за `auth:api` + `admin`.
 *
 *   Здесь — публичный эндпоинт, который дергает монитор. Из него НЕЛЬЗЯ
 *   отдавать ни точных версий, ни путей, ни свободного места в гигабайтах:
 *   номер версии фреймворка однозначно отображается на список известных
 *   уязвимостей, и публиковать его анонимному клиенту — разведка бесплатно.
 *   Поэтому полезная нагрузка здесь ровно та, что описана в контракте, и
 *   ничего сверх неё.
 *
 * ПОЧЕМУ 503, А НЕ ВСЕГДА 200
 *   Это и есть причина существования эндпоинта. До него в проекте были
 *   `GET /api/v1/ping` и `GET /up`, и оба отвечали 200 всегда, ничего не
 *   проверяя: монитор, повешенный на них, показывал зелёный свет при мёртвой
 *   базе. «Эндпоинт, который выглядит как проверка здоровья, но им не
 *   является» хуже отсутствия эндпоинта — отсутствие хотя бы не врёт.
 *
 *   `/ping` и `/up` оставлены как liveness-пробы (процесс отвечает?) и
 *   описаны в своих маршрутах; состояние зависимостей проверяет только
 *   этот эндпоинт. Решение о критичности зависимостей — в `HealthProbe`.
 */
final class HealthController extends Controller
{
    public function show(HealthProbe $probe): JsonResponse
    {
        $checks = $probe->checks();

        return response()->json([
            'status' => $probe->status(),
            'database' => $checks['database']['state'],
            'redis' => $checks['redis']['state'],
            'queue' => $checks['queue']['state'],
            'version' => (string) config('nabilet.version', '0.0.0'),
            'timestamp' => now()->toIso8601String(),
        ], $probe->httpCode());
    }
}
