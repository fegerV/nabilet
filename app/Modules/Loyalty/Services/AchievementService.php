<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Services;

use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Domain\AchievementPolicy;
use Nabilet\Modules\Loyalty\Domain\BonusPolicy;

final class AchievementService
{
    public function forUser(User $user): array
    {
        $catalog = json_decode(file_get_contents(resource_path('data/club-achievements.json')), true, 512, JSON_THROW_ON_ERROR);
        // Cross-organization personal read, explicitly scoped to the authenticated owner.
        // A group booking counts once, refunds/revocations never qualify.
        $rows = DB::table('tickets as t')
            ->join('orders as o', 'o.id', '=', 't.order_id')
            ->join('sessions as s', 's.id', '=', 't.session_id')
            ->join('events as e', 'e.id', '=', 's.event_id')
            ->leftJoin('event_categories as c', 'c.id', '=', 'e.category_id')
            ->where('o.user_id', $user->getKey())
            ->whereIn('o.status', ['paid', 'partially_refunded'])
            ->where('t.status', 'used')->whereNotNull('t.used_at')
            ->whereNull('t.refunded_at')->whereNull('t.revoked_at')->whereNull('t.cancelled_at')
            ->select(['s.id as session_id', 'e.id as event_id', 's.venue_id', 's.starts_at', 's.timezone', 'c.slug as category', 't.used_at'])
            ->orderBy('t.used_at')->get()->unique('session_id');
        $artistRows = $rows->isEmpty() ? collect() : DB::table('artist_event as ae')
            ->join('artists as a', 'a.id', '=', 'ae.artist_id')
            ->whereIn('ae.event_id', $rows->pluck('event_id')->unique()->all())
            ->select(['ae.event_id', 'a.id', 'a.genre'])->get()->groupBy('event_id');
        $visits = $rows->map(static function ($row) use ($artistRows): array {
            $artists = $artistRows->get($row->event_id, collect());
            $start = null;
            if ($row->starts_at && $row->timezone) {
                try {
                    $start = (new \DateTimeImmutable($row->starts_at, new \DateTimeZone('UTC')))
                        ->setTimezone(new \DateTimeZone($row->timezone))->format('Y-m-d H:i:s');
                } catch (\Exception) {
                    // Missing/invalid timezone must not accidentally award a night badge.
                }
            }
            return [
                'session_id' => $row->session_id, 'venue_id' => $row->venue_id,
                'category' => $row->category ?? '', 'local_start' => $start,
                'used_at' => str_replace(' ', 'T', $row->used_at).'Z',
                'artists' => $artists->pluck('id')->all(),
                'genres' => $artists->pluck('genre')->filter()->all(),
            ];
        })->values()->all();
        $settings = app(LoyaltySettingsService::class)->forAdmin();
        return [
            'achievements' => AchievementPolicy::evaluate($visits, $catalog),
            'visits' => count($visits),
            'levels' => $settings['levels'],
            'rules' => [
                'accrual_percent' => BonusPolicy::ACCRUAL_PERCENT,
                'spend_cap_percent' => BonusPolicy::SPEND_CAP_PERCENT,
                'lifetime_months' => BonusPolicy::LIFETIME_MONTHS,
                'early_access_enabled' => $settings['enabled'],
                'early_access_hours' => $settings['hours'],
                'early_access_min_level_slug' => $settings['min_level_slug'],
            ],
        ];
    }
}
