<?php

declare(strict_types=1);

namespace Nabilet\Modules\Kiosk\Services;

use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Kiosk\Domain\KioskDevice as DevicePolicy;
use Nabilet\Modules\Kiosk\Domain\KioskSale as SalePolicy;

/**
 * Аналитика парка киосков.
 *
 * Считается по двум таблицам модуля, без join'ов в витринные таблицы: продажа
 * хранит сумму и статус в момент создания, поэтому отчёт не меняется, если
 * позже поменяется цена в событии. Деньги — целые копейки (minor units).
 *
 * «Онлайн» = heartbeats за окно `nabilet.kiosks.offline_after_minutes`.
 * Это честный признак связи, а не «успешно продаёт»: киоск с выключенными
 * продажами всё равно считается на связи.
 */
final class KioskAnalyticsService
{
    public function summary(int $organizationId): array
    {
        $offlineAfter = max(1, (int) config('nabilet.kiosks.offline_after_minutes', 5));
        $onlineSince = now()->subMinutes($offlineAfter);

        $devices = DB::table('kiosk_devices')
            ->where('organization_id', $organizationId)
            ->selectRaw(
                'COUNT(*) AS total, '
                . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active, '
                . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS disabled, '
                . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS revoked, '
                . 'SUM(CASE WHEN sales_enabled = 1 AND status = ? THEN 1 ELSE 0 END) AS selling, '
                . 'SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) AS online',
                [
                    DevicePolicy::STATUS_ACTIVE,
                    DevicePolicy::STATUS_DISABLED,
                    DevicePolicy::STATUS_REVOKED,
                    DevicePolicy::STATUS_ACTIVE,
                    $onlineSince,
                ]
            )
            ->first();

        $today = DB::table('kiosk_sales')
            ->where('organization_id', $organizationId)
            ->whereIn('status', [SalePolicy::STATUS_PAID, SalePolicy::STATUS_ISSUED])
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount_minor), 0) AS revenue')
            ->first();

        $week = DB::table('kiosk_sales')
            ->where('organization_id', $organizationId)
            ->whereIn('status', [SalePolicy::STATUS_PAID, SalePolicy::STATUS_ISSUED])
            ->where('created_at', '>=', now()->subDays(7)->startOfDay())
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount_minor), 0) AS revenue')
            ->first();

        $top = DB::table('kiosk_sales')
            ->join('kiosk_devices', 'kiosk_devices.id', '=', 'kiosk_sales.kiosk_device_id')
            ->where('kiosk_sales.organization_id', $organizationId)
            ->whereIn('kiosk_sales.status', [SalePolicy::STATUS_PAID, SalePolicy::STATUS_ISSUED])
            ->where('kiosk_sales.created_at', '>=', now()->subDays(7)->startOfDay())
            ->groupBy('kiosk_devices.id', 'kiosk_devices.name')
            ->orderByDesc(DB::raw('SUM(kiosk_sales.amount_minor)'))
            ->limit(10)
            ->select(
                'kiosk_devices.id AS device_id',
                'kiosk_devices.name AS device_name',
                DB::raw('COUNT(*) AS sales_count'),
                DB::raw('SUM(kiosk_sales.amount_minor) AS revenue_minor')
            )
            ->get();

        return [
            'devices' => [
                'total' => (int) ($devices->total ?? 0),
                'active' => (int) ($devices->active ?? 0),
                'disabled' => (int) ($devices->disabled ?? 0),
                'revoked' => (int) ($devices->revoked ?? 0),
                'selling' => (int) ($devices->selling ?? 0),
                'online' => (int) ($devices->online ?? 0),
            ],
            'today' => [
                'sales' => (int) ($today->count ?? 0),
                'revenue_minor' => (int) ($today->revenue ?? 0),
            ],
            'week' => [
                'sales' => (int) ($week->count ?? 0),
                'revenue_minor' => (int) ($week->revenue ?? 0),
            ],
            'top' => $top->map(static fn ($row): array => [
                'device_id' => (int) $row->device_id,
                'device_name' => (string) $row->device_name,
                'sales_count' => (int) $row->sales_count,
                'revenue_minor' => (int) $row->revenue_minor,
            ])->all(),
            'offline_after_minutes' => $offlineAfter,
        ];
    }

    public function deviceStats(int $deviceId, int $organizationId): array
    {
        $row = DB::table('kiosk_sales')
            ->where('kiosk_device_id', $deviceId)
            ->where('organization_id', $organizationId)
            ->selectRaw(
                'COUNT(*) AS total, '
                . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS issued, '
                . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failed, '
                . 'COALESCE(SUM(CASE WHEN status IN (?, ?) THEN amount_minor ELSE 0 END), 0) AS revenue',
                [
                    SalePolicy::STATUS_ISSUED,
                    SalePolicy::STATUS_FAILED,
                    SalePolicy::STATUS_PAID,
                    SalePolicy::STATUS_ISSUED,
                ]
            )
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'issued' => (int) ($row->issued ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'revenue_minor' => (int) ($row->revenue ?? 0),
        ];
    }
}
