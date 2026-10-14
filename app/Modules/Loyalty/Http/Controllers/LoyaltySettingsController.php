<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Nabilet\Modules\Loyalty\Domain\ReferralPolicy;
use Nabilet\Modules\Loyalty\Services\LoyaltySettingsService;

/**
 * Настройки «Клуба зрителей» из админки: ранний доступ (фаза 3b) и
 * реферальная программа (фаза 4).
 *
 * Только сотрудник (`admin` поверх `auth:api`): ранний доступ открывает
 * продажу раньше общего старта, а реферальная награда — это бонусы, то есть
 * и то и другое двигает деньги, а не косметику витрины.
 *
 * ПОЧЕМУ ЧАСТИЧНОЕ ОБНОВЛЕНИЕ (`sometimes`)
 *   Две независимые группы настроек отдаются одной формой и правятся одним
 *   экраном, но сохраняться могут порознь: числовая поправка награды не
 *   должна затирать порог раннего доступа, и наоборот. Признак «поля нет» —
 *   «не трогать», а не «записать пустоту»: форма присылает обе группы
 *   целиком, и частичная правка приходит из скриптов и тестов.
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
            'enabled' => ['sometimes', 'boolean'],
            'hours' => ['sometimes', 'integer', 'min:1', 'max:' . LoyaltySettingsService::MAX_HOURS],
            'min_level_slug' => ['sometimes', 'string', 'max:64'],
            // Награды — в минорных единицах, как и все `*_amount` в API. Потолок
            // спрашиваем у `ReferralPolicy`: «максимум награды» должно жить в
            // одном месте, а не в правиле валидации и в правиле domain'а.
            'referral_enabled' => ['sometimes', 'boolean'],
            'referral_inviter_bonus' => ['sometimes', 'integer', 'min:0', 'max:' . ReferralPolicy::MAX_BONUS],
            'referral_invited_bonus' => ['sometimes', 'integer', 'min:0', 'max:' . ReferralPolicy::MAX_BONUS],
        ]);

        $hasEarlyAccess = array_intersect(['enabled', 'hours', 'min_level_slug'], array_keys($data)) !== [];
        $hasReferral = array_intersect(['referral_enabled', 'referral_inviter_bonus', 'referral_invited_bonus'], array_keys($data)) !== [];

        if ($hasEarlyAccess) {
            // Порог проверяется по живым уровням, а не по `in:` — список ведёт
            // сид, и зашитый `in:` расходился бы с ним при первом же новом уровне.
            $known = array_column($this->settings->forAdmin()['levels'], 'slug');
            $minLevelSlug = (string) ($data['min_level_slug'] ?? $this->settings->forAdmin()['min_level_slug']);

            if (!in_array($minLevelSlug, $known, true)) {
                throw ValidationException::withMessages([
                    'min_level_slug' => ['Уровня клуба с таким кодом нет.'],
                ]);
            }

            $this->settings->save(
                (bool) ($data['enabled'] ?? $this->settings->forAdmin()['enabled']),
                (int) ($data['hours'] ?? $this->settings->forAdmin()['hours']),
                $minLevelSlug,
            );
        }

        if ($hasReferral) {
            $this->settings->saveReferral(
                (bool) ($data['referral_enabled'] ?? $this->settings->forAdmin()['referral_enabled']),
                (int) ($data['referral_inviter_bonus'] ?? $this->settings->forAdmin()['referral_inviter_bonus']),
                (int) ($data['referral_invited_bonus'] ?? $this->settings->forAdmin()['referral_invited_bonus']),
            );
        }

        return response()->json(['data' => $this->settings->forAdmin()]);
    }
}
