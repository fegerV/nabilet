<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Nabilet\Modules\Agent\Mcp\Tools\Concerns\RequiresAgentRead;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Nabilet\Modules\Agent\Support\AgentScope;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Сводные продажи за период — «как идут дела» одним вызовом.
 *
 * Агрегаты считаются прямо здесь, а не через `AnalyticsReportService`:
 * тот НЕ ограничен организацией (служит общей панели), а агент обязан видеть
 * только организации владельца токена — переиспользование unscoped-сервиса
 * при нескольких организаторах на установке выдало бы чужую выручку тихо,
 * круглым числом, без единой строки о другом арендаторе.
 *
 * Посетителей (analytics_events) в ответе нет: таблица глобальная, честно
 * срезать её по организации нельзя — а число «посетителей всех организаторов
 * разом» вводит агента в заблуждение. Продажи — те, что принадлежат владельцу
 * токена, и только они.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Name('sales_summary')]
#[Description('Сводные продажи за последние N дней: выручка (оплаченные заказы), число заказов по статусам, проданные билеты. Период — целое число дней, по умолчанию 30.')]
class SalesSummaryTool extends Tool
{
    use RequiresAgentRead;

    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()
                ->description('Сколько последних дней учитывать. Максимум 365.')
                ->default(30),
        ];
    }

    public function handle(Request $request)
    {
        if ($denied = $this->guardAgentRead($request)) {
            return $denied;
        }
        $validated = $request->validate(
            ['days' => ['integer', 'min:1', 'max:365']],
            ['days.max' => 'Период ограничен 365 днями; для более длинного окна сузьте запрос.'],
        );

        $scope = AgentScope::resolve($request->user());

        if ($scope === null) {
            return Response::error('Токен не привязан ни к одной организации — данных для показа нет.');
        }

        $days = (int) ($validated['days'] ?? 30);
        $since = now()->subDays($days);
        $paid = OrderStateMachine::PAID;

        $summary = $scope->run(function (array $orgIds) use ($since, $paid): array {
            $inScope = fn ($query) => $query->whereIn('organization_id', $orgIds);

            // Выручка и счётчики заказов — одним проходом по orders за период.
            $orders = $inScope(DB::table('orders')->where('created_at', '>=', $since))
                ->selectRaw("status, COUNT(*) as cnt, COALESCE(SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END), 0) as paid_sum", [$paid])
                ->groupBy('status')
                ->get();

            $byStatus = [];
            $revenue = 0;
            $total = 0;

            foreach ($orders as $row) {
                $byStatus[(string) $row->status] = (int) $row->cnt;
                $revenue += (int) $row->paid_sum;
                $total += (int) $row->cnt;
            }

            // Проданные билеты за период (в т.ч. по прошлым заказам — созданные сейчас).
            $tickets = (int) $inScope(DB::table('tickets')->where('created_at', '>=', $since))->count();

            return [$byStatus, $revenue, $total, $tickets];
        });

        [$byStatus, $revenue, $total, $tickets] = $summary;

        return Response::structured([
            'period_days' => $days,
            'since' => $since->toDateString(),
            'currency_note' => 'суммы — в валюте заказа, копейки/минорные единицы как в БД',
            'revenue_paid' => $revenue,
            'orders_total' => $total,
            'orders_by_status' => $byStatus,
            'tickets_sold' => $tickets,
        ]);
    }
}
