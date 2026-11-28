<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
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
use Illuminate\Support\Facades\DB;

/**
 * Детали заказа: позиции, билеты, контакты покупателя.
 *
 * Здесь, и только здесь, агент получает персональные данные покупателя:
 * в списке (search_orders) их сознательно нет — выдача ПДн обоснована
 * конкретной задачей по конкретному заказу («куда писать зрителю»), а не
 * проходом по выборке.
 *
 * Контакты шлются как есть: владелец токена — сотрудник организации,
 * которая и так видит эти данные в админке; ничего нового агенту не
 * открывается, ограничена только организация.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Name('get_order')]
#[Description('Детали заказа по public_id: позиции, билеты (номер, статус), контакты покупателя. Заказы чужих организаций неотличимы от несуществующих.')]
class GetOrderTool extends Tool
{
    use RequiresAgentRead;

    public function schema(JsonSchema $schema): array
    {
        return [
            'public_id' => $schema->string()
                ->description('Публичный идентификатор заказа (26 символов, из search_orders).')
                ->required(),
        ];
    }

    public function handle(Request $request)
    {
        if ($denied = $this->guardAgentRead($request)) {
            return $denied;
        }
        $validated = $request->validate(
            ['public_id' => ['required', 'string', 'max:26']],
            ['public_id.required' => 'Укажите public_id заказа — получить его можно инструментом search_orders.'],
        );

        $scope = AgentScope::resolve($request->user());

        if ($scope === null) {
            return Response::error('Токен не привязан ни к одной организации — данных для показа нет.');
        }

        $publicId = (string) $validated['public_id'];

        $order = $scope->run(function (array $orgIds) use ($publicId) {
            $order = DB::table('orders')
                ->whereIn('organization_id', $orgIds)
                ->where('public_id', $publicId)
                ->first();

            if ($order === null) {
                return null;
            }

            // `qr_payload` и хэши QR не выдаются: это сам доступ к билету.
            // Просмотр заказа агентом не должен превращаться в выдачу проходимого QR.
            // Позиции описываются снапшотами (`order_items.name` не существует) —
            // что покупали: событие, сеанс, место.
            $items = DB::table('order_items')
                ->where('order_id', $order->id)
                ->select(['event_title_snapshot', 'session_title_snapshot', 'venue_title_snapshot', 'quantity', 'unit_price', 'total_amount'])
                ->get();

            $tickets = DB::table('tickets')
                ->where('order_id', $order->id)
                ->select(['public_id', 'ticket_number', 'status', 'holder_name', 'used_at'])
                ->get();

            return [$order, $items, $tickets];
        });

        if ($order === null) {
            return Response::error(sprintf('Заказ %s не найден среди организаций владельца токена.', $publicId));
        }

        [$order, $items, $tickets] = $order;

        return Response::structured([
            'public_id' => (string) $order->public_id,
            'order_number' => (string) $order->order_number,
            'status' => (string) $order->status,
            'payment_status' => (string) $order->payment_status,
            'subtotal_amount' => (int) $order->subtotal_amount,
            'discount_amount' => (int) $order->discount_amount,
            'bonus_amount' => (int) $order->bonus_amount,
            'fee_amount' => (int) $order->fee_amount,
            'total_amount' => (int) $order->total_amount,
            'currency' => (string) $order->currency,
            // Контакты частично маскируются: агенту достаточно формата и
            //-domain/последних цифр, полные ПДн остаются в админке организации.
            'customer' => [
                'name' => $this->maskName($order->customer_name ?? null),
                'email' => $this->maskEmail($order->customer_email ?? null),
                'phone' => $this->maskPhone($order->customer_phone ?? null),
            ],
            'created_at' => (string) $order->created_at,
            'paid_at' => $order->paid_at !== null ? (string) $order->paid_at : null,
            'items' => $items->map(fn (object $item): array => [
                'event' => $item->event_title_snapshot !== null ? (string) $item->event_title_snapshot : null,
                'session' => $item->session_title_snapshot !== null ? (string) $item->session_title_snapshot : null,
                'venue' => $item->venue_title_snapshot !== null ? (string) $item->venue_title_snapshot : null,
                'quantity' => (int) $item->quantity,
                'unit_price' => (int) $item->unit_price,
                'total_amount' => (int) $item->total_amount,
            ])->all(),
            'tickets' => $tickets->map(fn (object $ticket): array => [
                'public_id' => (string) $ticket->public_id,
                'ticket_number' => (string) $ticket->ticket_number,
                'status' => (string) $ticket->status,
                'holder_name' => $this->maskName($ticket->holder_name ?? null),
                'used_at' => $ticket->used_at !== null ? (string) $ticket->used_at : null,
            ])->all(),
        ]);
    }

    private function maskName(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $text = (string) $value;

        return mb_substr($text, 0, 1) . '***';
    }

    private function maskEmail(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $parts = explode('@', (string) $value, 2);
        if (count($parts) !== 2) {
            return '***';
        }

        return mb_substr($parts[0], 0, 1) . '***@' . $parts[1];
    }

    private function maskPhone(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';
        if (strlen($digits) < 5) {
            return '***';
        }

        return substr($digits, 0, 2) . '***' . substr($digits, -4);
    }
}
