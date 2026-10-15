<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Services\LoyaltySettingsService;
use Nabilet\Modules\Loyalty\Services\ReferralService;

/**
 * «Пригласите друга» — ссылка и статистика реферальной программы (фаза 4).
 *
 * Владелец всегда из `auth:api`, никогда из query/path: тот же инвариант, что
 * у `BonusController` — путь обещает «моё», и подставить чужое в него нечем.
 *
 * Ссылка выдаётся лениво, при первом открытии кабинета: заводить код каждому
 * гостю, открывшему афишу, значило бы держать таблицу ссылок, которыми никто
 * не пользуется (`ReferralCode::forUserOrCreate()`).
 */
class ReferralController extends Controller
{
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly LoyaltySettingsService $settings,
    ) {
    }

    /**
     * GET /api/v1/me/referral — код, ссылка, условия программы и статистика.
     *
     * Суммы наград — в МИНОРНЫХ единицах, как и все деньги в API
     * (`MoneyTotals.bonus_amount`): клиент форматирует для показа, сервер не
     * отдаёт decimal-строки. Когда программа выключена, ссылка всё равно
     * выдаётся: приглашение без награды остаётся приглашением, а кабинет не
     * должен показывать «функция недоступна» тому, кто уже разослал ссылки.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $admin = $this->settings->forAdmin();

        return response()->json([
            'data' => [
                'code' => $this->referrals->codeFor($user),
                'link' => $this->referrals->linkFor($user),
                'enabled' => $admin['referral_enabled'],
                'inviter_bonus' => $admin['referral_inviter_bonus'],
                'invited_bonus' => $admin['referral_invited_bonus'],
                'stats' => $this->referrals->statsFor($user),
            ],
        ]);
    }
}
