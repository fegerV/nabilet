<?php

declare(strict_types=1);

namespace Nabilet\Modules\Kiosk\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Tenancy\OrganizationContext;
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

        // У KioskSale включён глобальный читающий TenantScoped. В кроне НЕТ
        // HTTP-запроса и контекста организации — без bypass'а первая же
        // выборка бросила бы TenantContextMissingError. Мы читаем ВСЕ продажи:
        // каждая уже привязана к своей организации на уровне строки.
        app(OrganizationContext::class)->withoutScope(
            fn () => KioskSale::query()
                // PAID обязательно включён: билет может быть выпущен позже,
                // когда заказ уже оплачен. Без повторного выбора строка застряла
                // бы в `paid` с qr_payload = null навсегда (переход PAID→ISSUED
                // объявлен законным в KioskSale::can).
                ->whereIn('status', [
                    SalePolicy::STATUS_PENDING,
                    SalePolicy::STATUS_AWAITING_PAYMENT,
                    SalePolicy::STATUS_PAID,
                ])
                ->orderBy('id')
                ->chunkById(200, function ($sales) use (&$count, $paidAt): void {
                    foreach ($sales as $sale) {
                        /** @var KioskSale $sale */
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
                })
        );

        $this->info("Синхронизировано продаж: {$count}");

        return self::SUCCESS;
    }
}
