<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Nabilet\Modules\Agent\Mcp\Tools\Concerns\PreparesAgentAction;
use Nabilet\Modules\Agent\Support\AgentCapabilities;

/**
 * Подготовка возврата по заказу.
 *
 * Деньги — самая чувствительная операция: подготовка не списывает ничего,
 * одобрение в админке требует отдельной фразы REFUND, а исполнение идёт через
 * штатный RefundService с его идемпотентностью и проводкой в payment_transactions.
 */
#[Name('refund')]
#[Description('Подготовить возврат по оплаченному заказу. Требует одобрения человека с вводом REFUND; реально списывает только confirm_agent_action.')]
final class RefundTool extends Tool
{
    use PreparesAgentAction;

    public function schema(JsonSchema $schema): array
    {
        return [
            'order_public_id' => $schema->string()->description('Публичный ID заказа (26 символов).'),
            'amount' => $schema->integer()->description('Сумма возврата в минорных единицах; без неё — полный возврат.'),
            'reason' => $schema->string()->description('Причина возврата (видна администратору при одобрении).'),
            'organization_id' => $schema->integer()->description('Организация, если у токена их несколько.'),
        ];
    }

    // Return type deliberately omitted: Response::structured() returns ResponseFactory.
    public function handle(Request $request)
    {
        $v = $request->validate([
            'order_public_id' => ['required', 'string', 'max:26'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
            'organization_id' => ['nullable', 'integer'],
        ]);

        $amount = isset($v['amount'])
            ? sprintf(' на сумму %d минорных единиц', $v['amount'])
            : ' (полный возврат)';

        return $this->stage(
            $request,
            'refund',
            AgentCapabilities::REFUNDS_WRITE,
            $v,
            sprintf('Вернуть по заказу %s%s. Причина: %s', $v['order_public_id'], $amount, $v['reason'] ?? 'не указана'),
        );
    }
}
