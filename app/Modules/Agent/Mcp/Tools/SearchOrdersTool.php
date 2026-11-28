<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Agent\Support\AgentScope;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Nabilet\Modules\Agent\Mcp\Tools\Concerns\RequiresAgentRead;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Поиск заказов организации владельца токена.
 *
 * Статусы — константы `OrderStateMachine` (в БД живут строки `paid`,
 * `awaiting_payment`, …); enum'ом описания не берутся, чтобы список фильтра
 * не разошёлся с машиной состояний при её расширении.
 *
 * Email/телефон покупателя в списке НЕ выдаются: пачка персональных данных
 * без задачи для выбора заказа — лишний контекст для модели и лишняя
 * утечка при копировании разговора. Полные данные отдаёт get_order по
 * одному заказу, где они обоснованы задачей («куда писать покупателю»).
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Name('search_orders')]
#[Description('Ищет заказы за последние N дней: фильтры по статусу и событию. Возвращает public_id заказа (ключ для get_order), номер, статус и сумму.')]
class SearchOrdersTool extends Tool
{
    use RequiresAgentRead;

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum([
                    OrderStateMachine::PENDING,
                    OrderStateMachine::AWAITING_PAYMENT,
                    OrderStateMachine::PAID,
                    OrderStateMachine::PARTIALLY_REFUNDED,
                    OrderStateMachine::REFUNDED,
                    OrderStateMachine::CANCELLED,
                    OrderStateMachine::EXPIRED,
                ])
                ->description('Фильтр по статусу заказа. Пусто — вернуть любые.')
                ->default(''),
            'event_public_id' => $schema->string()
                ->description('Только заказы этого события (public_id из search_events). Пусто — по всем.')
                ->default(''),
            'days' => $schema->integer()
                ->description('Сколько последних дней учитывать (максимум 365).')
                ->default(30),
            'limit' => $schema->integer()
                ->description('Сколько заказов вернуть (1–100), свежие первыми.')
                ->default(20),
        ];
    }

    public function handle(Request $request)
    {
        if ($denied = $this->guardAgentRead($request)) {
            return $denied;
        }
        $statuses = implode(',', [
            OrderStateMachine::PENDING,
            OrderStateMachine::AWAITING_PAYMENT,
            OrderStateMachine::PAID,
            OrderStateMachine::PARTIALLY_REFUNDED,
            OrderStateMachine::REFUNDED,
            OrderStateMachine::CANCELLED,
            OrderStateMachine::EXPIRED,
        ]);

        $validated = $request->validate(
            [
                'status' => ['nullable', 'string', 'in:'.$statuses],
                'event_public_id' => ['nullable', 'string', 'max:26'],
                'days' => ['integer', 'min:1', 'max:365'],
                'limit' => ['integer', 'min:1', 'max:100'],
            ],
            [
                'status.in' => 'Неизвестный статус заказа. Доступны: '.$statuses.'.',
                'limit.max' => 'Лимит ограничен 100 — уточните фильтры, чтобы сузить выборку.',
            ],
        );

        $scope = AgentScope::resolve($request->user());

        if ($scope === null) {
            return Response::error('Токен не привязан ни к одной организации — данных для показа нет.');
        }

        $status = (string) ($validated['status'] ?? '');
        $eventPublicId = (string) ($validated['event_public_id'] ?? '');
        $days = (int) ($validated['days'] ?? 30);
        $limit = (int) ($validated['limit'] ?? 20);

        $orders = $scope->run(function (array $orgIds) use ($status, $eventPublicId, $days, $limit) {
            $builder = DB::table('orders')
                ->whereIn('organization_id', $orgIds)
                ->where('created_at', '>=', now()->subDays($days))
                ->orderByDesc('created_at')
                ->limit($limit)
                ->select(['public_id', 'order_number', 'status', 'payment_status', 'total_amount', 'currency', 'created_at', 'paid_at']);

            if ($status !== '') {
                $builder->where('status', $status);
            }

            if ($eventPublicId !== '') {
                $builder->whereIn('event_id', DB::table('events')
                    ->whereIn('organization_id', $orgIds)
                    ->where('public_id', $eventPublicId)
                    ->select('id'));
            }

            return $builder->get()->map(fn (object $row): array => [
                'public_id' => (string) $row->public_id,
                'order_number' => (string) $row->order_number,
                'status' => (string) $row->status,
                'payment_status' => (string) $row->payment_status,
                'total_amount' => (int) $row->total_amount,
                'currency' => (string) $row->currency,
                'created_at' => (string) $row->created_at,
                'paid_at' => $row->paid_at !== null ? (string) $row->paid_at : null,
            ])->all();
        });

        if ($orders === []) {
            return Response::text('Заказов по фильтрам не найдено (в организациях владельца токена).');
        }

        return Response::structured(['count' => count($orders), 'orders' => $orders]);
    }
}
