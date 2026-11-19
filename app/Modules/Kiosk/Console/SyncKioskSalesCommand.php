<?php

declare(strict_types=1);

namespace Nabilet\Modules\Kiosk\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Kiosk\Domain\KioskSale as SalePolicy;
use Nabilet\Modules\Kiosk\Models\KioskSale;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Связывает продажи киоска с реальным состоянием заказа.
 *
 * Билет подписывается на сервере в момент webhook'а об оплате — киоск не имеет
 * секрета подписи. Поэтому статус продажи догоняет заказ: пока webhook не
 * придёт, `qr_payload` остаётся null и экран честно показывает «ожидание
 * оплаты». Команда идемпотентна и может ходить каждую минуту.
 */
final class SyncKioskSalesCommand extends Command
{
    protected $signature = 'kiosks:sync-sales';

    protected $description = 'Синхронизировать продажи киосков с оплатой и выпуском билетов';

    public function handle(): int
    {
        $paidAt = now();
        $count = 0;

        KioskSale::query()
            ->whereIn('status', [SalePolicy::STATUS_PENDING, SalePolicy::STATUS_AWAITING_PAYMENT])
            ->orderBy('id')
            ->chunkById(200, function ($sales) use (&$count, $paidAt): void {
                foreach ($sales as $sale) {
                    $order = DB::table('orders')->where('id', $sale->order_id)->first();

                    if ($order === null || $order->status !== OrderStateMachine::PAID) {
                        continue;
                    }

                    $ticket = DB::table('order_items')
                        ->where('order_id', $sale->order_id)
                        ->whereNotNull('qr_payload')
                        ->where('ticket_status', 'issued')
                        ->orderBy('id')
                        ->first();

                    $sale->forceFill([
                        'status' => $ticket !== null ? SalePolicy::STATUS_ISSUED : SalePolicy::STATUS_PAID,
                        'paid_at' => $sale->paid_at ?? $paidAt,
                        'qr_payload' => $ticket?->qr_payload,
                        'issued_at' => $ticket !== null ? ($sale->issued_at ?? $paidAt) : null,
                    ])->save();

                    $count++;
                }
            });

        $this->info("Синхронизировано продаж: {$count}");

        return self::SUCCESS;
    }
}
