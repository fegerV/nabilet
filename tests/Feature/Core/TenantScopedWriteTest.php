<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Orders\Models\Order;
use Tests\TestCase;

/**
 * Write-side поведение `TenantScoped` (фаза 2).
 *
 * Трейт должен:
 *   - заполнять `organization_id` из активного контекста, когда колонка не задана;
 *   - отклонять явно заданную чужую организацию (`NotFoundError`, неотличимо от
 *     несуществующего ресурса);
 *   - не трогать строку, когда контекста нет (системное создание).
 */
final class TenantScopedWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Не даём контексту «протечь» в следующие тесты.
        app(OrganizationContext::class)->clear();

        parent::tearDown();
    }

    private function organization(): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Org',
            'slug' => 'org-' . Str::random(6),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function minimalOrder(array $extra = []): Order
    {
        $order = new Order();
        $order->fill(array_merge([
            'subtotal_amount' => 0,
            'total_amount' => 0,
            'customer_email' => 'buyer@example.com',
        ], $extra));
        $order->save();

        return $order;
    }

    public function test_order_without_org_is_filled_from_the_context(): void
    {
        $org = $this->organization();
        app(OrganizationContext::class)->set((string) $org);

        $order = $this->minimalOrder();

        $this->assertSame($org, (int) $order->organization_id);
    }

    public function test_order_with_a_mismatched_org_is_rejected(): void
    {
        $contextOrg = $this->organization();
        $otherOrg = $this->organization();
        app(OrganizationContext::class)->set((string) $contextOrg);

        try {
            $this->minimalOrder(['organization_id' => $otherOrg]);
            $this->fail('Ожидался NotFoundError при несовпадении организации');
        } catch (NotFoundError $e) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_order_survives_without_any_context(): void
    {
        $order = $this->minimalOrder(['organization_id' => $this->organization()]);

        $this->assertNotNull($order->id);
        $this->assertSame($order->organization_id, (int) $order->organization_id);
    }
}
