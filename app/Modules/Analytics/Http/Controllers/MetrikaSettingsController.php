<?php

declare(strict_types=1);

namespace Nabilet\Modules\Analytics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Modules\Analytics\Services\MetrikaSettings;

/**
 * Админский CRUD настроек Яндекс Метрики (таблица `metrika_settings`).
 *
 * Публичный конфиг (MetrikaController) отдаёт SPA только несекретную часть;
 * здесь админ меняет счётчик и цели без деплоя: значения пишутся в таблицу
 * поверх config/env (см. MetrikaSettings::all()). Секрет counter_auth сюда
 * намеренно не принимается — он живёт только в .env (принцип №7 ROADMAP).
 */
class MetrikaSettingsController extends Controller
{
    /** Ключи, которые админ может переопределять через UI. */
    private const EDITABLE = ['counter_id', 'counter_type', 'safe_stage', 'accurate_track', 'ecommerce', 'currency', 'goals'];

    public function __construct(private readonly MetrikaSettings $settings)
    {
    }

    /** GET /api/v1/admin/analytics/metrika — текущие настройки для формы. */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    /** PUT /api/v1/admin/analytics/metrika — сохранить переопределения. */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'counter_id'    => ['sometimes', 'nullable', 'integer', 'min:0'],
            'counter_type'  => ['sometimes', 'nullable', 'in:web,hit'],
            'safe_stage'    => ['sometimes', 'nullable', 'boolean'],
            'accurate_track' => ['sometimes', 'nullable', 'boolean'],
            'ecommerce'     => ['sometimes', 'nullable', 'boolean'],
            'currency'      => ['sometimes', 'nullable', 'string', 'size:3'],
            'goals'         => ['sometimes', 'nullable', 'array'],
            'goals.*'       => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        foreach ($validated as $key => $value) {
            if (!in_array($key, self::EDITABLE, true)) {
                continue;
            }
            // Пустая строка в поле ID = «выключить интеграцию» (0), а не сломанный int.
            if ($key === 'counter_id' && $value === '') {
                $value = 0;
            }
            $this->settings->set($key, $value);
        }

        return response()->json(['data' => $this->settings->all()]);
    }
}
