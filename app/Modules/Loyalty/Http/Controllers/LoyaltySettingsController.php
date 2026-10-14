<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Nabilet\Modules\Loyalty\Services\LoyaltySettingsService;

/**
 * Ранний доступ клуба — настройка из админки.
 *
 * Только сотрудник (`admin` поверх `auth:api`): правило открывает продажу
 * раньше общего старта, то есть двигает деньги, а не косметику витрины.
 */
class LoyaltySettingsController extends Controller
{
    public function __construct(private readonly LoyaltySettingsService $settings)
    {
    }

    /** GET /api/v1/admin/loyalty/settings */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    /** PUT /api/v1/admin/loyalty/settings */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'hours' => ['required', 'integer', 'min:1', 'max:' . LoyaltySettingsService::MAX_HOURS],
            'min_level_slug' => ['required', 'string', 'max:64'],
        ]);

        // Порог проверяется по живым уровням, а не по `in:` — список ведёт сид,
        // и зашитый `in:` расходился бы с ним при первом же новом уровне.
        $known = array_column($this->settings->forAdmin()['levels'], 'slug');

        if (!in_array($data['min_level_slug'], $known, true)) {
            throw ValidationException::withMessages([
                'min_level_slug' => ['Уровня клуба с таким кодом нет.'],
            ]);
        }

        $this->settings->save(
            (bool) $data['enabled'],
            (int) $data['hours'],
            (string) $data['min_level_slug'],
        );

        return response()->json(['data' => $this->settings->forAdmin()]);
    }
}
