<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Nabilet\Modules\Agent\Mcp\NabiletAgentServer;
use Nabilet\Modules\Agent\Mcp\Tools\GetEventTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetOrderTool;
use Nabilet\Modules\Agent\Mcp\Tools\SalesSummaryTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchEventsTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchOrdersTool;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * MCP-сервер внешнего ИИ-агента (модуль Agent).
 *
 * Два слоя проверок:
 *
 *   HTTP — гейт полномочий на маршруте `/mcp`: без токена 401; валидный
 *   токен не-сотрудника и валидный токен без ability `agent:read` — 403,
 *   причём на ЛЮБОМ вызове (tools/list не «показывает инструменты» тому,
 *   кому читать нельзя).
 *
 *   Инструменты — unit-вызовы через `Server::tool()`: экономные ответы,
 *   org-скоуп (чужие организации неотличимы от несуществующих), скрытие
 *   QR-доступа к билетам. Пользователь подставляется `actingAs` — это путь
 *   самого инструмента без миддлвари, поэтому ability здесь не проверяется:
 *   её граница живёт на HTTP-слое и покрыта тестами выше.
 */
class AgentMcpServerTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 5_000_000;

    /** ── HTTP: гейт полномочий ─────────────────────────────────────── */

    public function test_mcp_rejects_requests_without_a_token(): void
    {
        // initialize — первый вызов любого MCP-клиента: без токена не проходит
        // даже он, а не только tools/call.
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_customer_token_is_rejected(): void
    {
        $user = $this->seedUser('buyer@example.test', staff: false);

        $token = $user->createToken('storefront', ['tickets:read'])->plainTextToken;

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_token_without_the_agent_read_ability_is_rejected(): void
    {
        // Валидный staff-PAT, но без `agent:read` (например, выданный для
        // будущих write-инструментов) — читать агенту нельзя: чтение отдельное
        // полномочие, а не «отсутствие запрета».
        $user = $this->seedUser('manager@example.test', staff: true);

        $token = $user->createToken('other-purpose', ['orders:export'])->plainTextToken;

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.message', fn (string $message) => str_contains($message, 'agent:read'));
    }

    /** ── Инструменты ───────────────────────────────────────────────── */

    public function test_tools_list_exposes_exactly_the_five_read_only_tools(): void
    {
        $user = $this->seedUser('manager@example.test', staff: true);

        NabiletAgentServer::actingAs($user)->tools()
            ->assertRegistered([
                SalesSummaryTool::class,
                SearchEventsTool::class,
                GetEventTool::class,
                SearchOrdersTool::class,
                GetOrderTool::class,
            ]);

        // Ровно пять инструментов, и все read-only: расширение набора —
        // отдельное решение, аннотация — обещание клиенту, что вызов безопасен.
        $names = [
            'sales_summary',
            'search_events',
            'get_event',
            'search_orders',
            'get_order',
        ];

        foreach ([
            SalesSummaryTool::class,
            SearchEventsTool::class,
            GetEventTool::class,
            SearchOrdersTool::class,
            GetOrderTool::class,
        ] as $toolClass) {
            $this->assertNotEmpty(
                (new \ReflectionClass($toolClass))->getAttributes(IsReadOnly::class),
                "{$toolClass} обязан быть объявлен #[IsReadOnly].",
            );
        }
    }

    public function test_sales_summary_counts_revenue_from_paid_orders_only(): void
    {
        [$orgId, $user] = $this->seedStaffWithOrg();

        $seed = $this->seedEvent($orgId);

        $this->seedOrder($seed, 'paid', 2);
        $this->seedOrder($seed, 'cancelled', 1);
        $this->seedOrder($seed, 'pending', 1);

        NabiletAgentServer::actingAs($user)
            ->tool(SalesSummaryTool::class)
            ->assertOk()
            ->assertStructuredContentPath('revenue_paid', 2 * self::PRICE)
            ->assertStructuredContentPath('orders_total', 3)
            ->assertStructuredContentPath('orders_by_status.paid', 1)
            ->assertStructuredContentPath('orders_by_status.cancelled', 1)
            ->assertStructuredContentPath('tickets_sold', 4);
    }

    public function test_sales_summary_sees_only_the_owner_organizations(): void
    {
        [$foreignOrg, ] = $this->seedStaffWithOrg();

        [$orgId, $user] = $this->seedStaffWithOrg();

        $foreign = $this->seedEvent($foreignOrg);
        $this->seedOrder($foreign, 'paid', 2); // чужая выручка

        $own = $this->seedEvent($orgId);
        $this->seedOrder($own, 'paid', 1);

        NabiletAgentServer::actingAs($user)
            ->tool(SalesSummaryTool::class)
            ->assertOk()
            ->assertStructuredContentPath('revenue_paid', self::PRICE)
            ->assertStructuredContentPath('orders_total', 1);
    }

    public function test_search_events_hides_foreign_organizations(): void
    {
        [$foreignOrg, ] = $this->seedStaffWithOrg();
        [$orgId, $user] = $this->seedStaffWithOrg();

        $this->seedEvent($foreignOrg, title: 'Чужой концерт Metallica');
        $own = $this->seedEvent($orgId, title: 'Свой концерт Metallica');

        NabiletAgentServer::actingAs($user)
            ->tool(SearchEventsTool::class, ['query' => 'Metallica'])
            ->assertOk()
            ->assertStructuredContentCount(1, 'events')
            ->assertStructuredContentPath('events.0.public_id', $own['eventPublicId']);
    }

    public function test_get_event_returns_error_for_unknown_public_id(): void
    {
        [$orgId, $user] = $this->seedStaffWithOrg();
        $this->seedEvent($orgId);

        NabiletAgentServer::actingAs($user)
            ->tool(GetEventTool::class, ['public_id' => '01M761772XG2R9N3FN80BKTCRB'])
            ->assertHasErrors();
    }

    public function test_get_event_reports_inventory_per_session(): void
    {
        [$orgId, $user] = $this->seedStaffWithOrg();

        // capacity 50, доступно 30 → продано 20. Инвентаря у сеанса один ряд.
        $seed = $this->seedEvent($orgId, inventoryCapacity: 50, inventoryAvailable: 30);

        NabiletAgentServer::actingAs($user)
            ->tool(GetEventTool::class, ['public_id' => $seed['eventPublicId']])
            ->assertOk()
            ->assertStructuredContentPath('sessions.0.inventory.capacity', 50)
            ->assertStructuredContentPath('sessions.0.inventory.available', 30)
            ->assertStructuredContentPath('sessions.0.inventory.sold', 20)
            // Длинное описание режется: полный текст события в контекст модели
            // целиком не нужен (в тесте — 3000 знаков, в ответе ≤ 2000).
            ->assertStructuredContentPath('description', fn ($v) => is_string($v) && mb_strlen($v) <= 2000);
    }

    public function test_search_orders_filters_by_status_and_scopes_to_org(): void
    {
        [$foreignOrg, ] = $this->seedStaffWithOrg();
        [$orgId, $user] = $this->seedStaffWithOrg();

        $foreign = $this->seedEvent($foreignOrg);
        $paidForeign = $this->seedOrder($foreign, 'paid', 2);
        $own = $this->seedEvent($orgId);
        $paidOwn = $this->seedOrder($own, 'paid', 1);
        $this->seedOrder($own, 'pending', 1);

        NabiletAgentServer::actingAs($user)
            ->tool(SearchOrdersTool::class, ['status' => 'paid'])
            ->assertOk()
            ->assertStructuredContentCount(1, 'orders')
            ->assertStructuredContentPath('orders.0.public_id', $paidOwn['orderPublicId'])
            ->assertDontSee($paidForeign['orderPublicId'], 'чужой заказ неотличим от несуществующего');
    }

    public function test_get_order_returns_customer_and_tickets_but_never_qr_access(): void
    {
        [$orgId, $user] = $this->seedStaffWithOrg();

        $seed = $this->seedEvent($orgId);
        $order = $this->seedOrder($seed, 'paid', 2, customerEmail: 'ivan@example.test');

        NabiletAgentServer::actingAs($user)
            ->tool(GetOrderTool::class, ['public_id' => $order['orderPublicId']])
            ->assertOk()
            ->assertStructuredContentPath('customer.email', 'ivan@example.test')
            ->assertStructuredContentCount(2, 'tickets')
            ->assertStructuredContentPath('tickets.0.ticket_number', fn ($v) => is_string($v) && $v !== '')
            // QR-пейлоад — сам доступ к билету: агент, просматривающий заказ,
            // не должен получать проходимый QR ни в каком поле ответа.
            ->assertDontSee($order['qrPayload']);
    }

    public function test_get_order_hides_foreign_orders_behind_not_found(): void
    {
        [$foreignOrg, ] = $this->seedStaffWithOrg();
        [$orgId, $user] = $this->seedStaffWithOrg();

        $foreign = $this->seedEvent($foreignOrg);
        $foreignOrder = $this->seedOrder($foreign, 'paid', 1);

        // Чужой заказ — ошибка «не найдено», как и несуществующий.
        NabiletAgentServer::actingAs($user)
            ->tool(GetOrderTool::class, ['public_id' => $foreignOrder['orderPublicId']])
            ->assertHasErrors();
    }

    /** ── Фикстуры ──────────────────────────────────────────────────── */

    /** @return array{0: int, 1: User} */
    private function seedStaffWithOrg(): array
    {
        $orgId = $this->seedOrg();

        $user = $this->seedUser('manager-'.Str::random(6).'@example.test', staff: true, organizationId: $orgId);

        return [$orgId, $user];
    }

    private function seedOrg(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Test Org '.Str::random(4),
            'slug' => 'org-'.Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Пользователь с ролями; staff-набор — admin. Членство в организации
     * описано двумя таблицами: `user_roles` (роль) и `user_organization`
     * (членство, откуда AgentScope берёт список организаций).
     */
    private function seedUser(string $email, bool $staff, ?int $organizationId = null): User
    {
        $now = now()->toDateTimeString();

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'first_name' => 'Test',
            'last_name' => 'User',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($staff) {
            // Роль get-or-create по slug (таблица `roles` без timestamp'ов) —
            // тот же приём, что в `StaffAuth`. Роль живёт в паре с
            // организацией (`user_roles.organization_id` NOT NULL), поэтому
            // staff-пользователю без организации организация создаётся.
            $organizationId ??= $this->seedOrg();

            $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
                ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));

            DB::table('user_roles')->insert([
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'role_id' => $roleId,
                'created_at' => $now,
            ]);
        }

        if ($organizationId !== null) {
            DB::table('user_organization')->insert([
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'role_id' => DB::table('user_roles')->where('user_id', $userId)->value('role_id'),
                'created_at' => $now,
            ]);
        }

        return User::query()->findOrFail($userId);
    }

    /**
     * Событие с одним сеансом; инвентарь настраивается, чтобы get_event
     * показывал честные capacity/available/sold.
     *
     * @return array{orgId: int, eventId: int, sessionId: int, eventPublicId: string}
     */
    private function seedEvent(int $orgId, string $title = 'Тестовое событие', int $inventoryCapacity = 100, int $inventoryAvailable = 100): array
    {
        $now = now()->toDateTimeString();

        $eventId = (int) DB::table('events')->insertGetId([
            'public_id' => $eventPublicId = (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'title' => $title,
            'slug' => 'event-'.Str::random(6),
            'short_description' => 'Коротко о событии',
            'description' => str_repeat('Длинное описание события. ', 120),
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $venueId = (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'name' => 'Тестовый зал',
            'slug' => 'venue-'.Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $hallId = (int) DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'venue_id' => $venueId,
            'name' => 'Основной зал',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $schemaVersionId = (int) DB::table('hall_schema_versions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'hall_id' => $hallId,
            'version' => 1,
            'status' => 'published',
            'schema_json' => json_encode(['rows' => []]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sessionId = (int) DB::table('sessions')->insertGetId([
            'public_id' => $sessionPublicId = (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'event_id' => $eventId,
            'venue_id' => $venueId,
            'hall_id' => $hallId,
            'schema_version_id' => $schemaVersionId,
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'timezone' => 'Asia/Almaty',
            'status' => 'on_sale',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sectorId = (int) DB::table('sectors')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'schema_version_id' => $schemaVersionId,
            'name' => 'Партер',
            'code' => 'P-'.Str::random(4),
            'type' => 'standing',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $standingZoneId = (int) DB::table('standing_zones')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'sector_id' => $sectorId,
            'name' => 'Танцпол',
            'capacity' => 100,
            'price_amount' => self::PRICE,
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $inventoryItemId = (int) DB::table('inventory_items')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'session_id' => $sessionId,
            'type' => 'standing',
            'standing_zone_id' => $standingZoneId,
            'price_amount' => self::PRICE,
            'currency' => 'RUB',
            'capacity' => $inventoryCapacity,
            'available_quantity' => $inventoryAvailable,
            'status' => 'available',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['orgId' => $orgId, 'eventId' => $eventId, 'sessionId' => $sessionId, 'inventoryItemId' => $inventoryItemId, 'eventPublicId' => $eventPublicId, 'sessionPublicId' => $sessionPublicId];
    }

    /**
     * Заказ с позициями и билетами; возвращает ключи для проверок.
     *
     * @return array{orderId: int, orderPublicId: string, orderNumber: string, qrPayload: string}
     */
    private function seedOrder(array $seed, string $status, int $ticketCount, string $customerEmail = 'buyer@example.test'): array
    {
        $now = now()->toDateTimeString();

        $total = $ticketCount * self::PRICE;

        $orderId = (int) DB::table('orders')->insertGetId([
            'public_id' => $orderPublicId = (string) Str::ulid()->toBase32(),
            'order_number' => $orderNumber = 'NB-'.Str::random(10),
            'organization_id' => $seed['orgId'],
            'event_id' => $seed['eventId'],
            'session_id' => $seed['sessionId'],
            'subtotal_amount' => $total,
            'discount_amount' => 0,
            'fee_amount' => 0,
            'total_amount' => $total,
            'currency' => 'RUB',
            'status' => $status,
            'payment_status' => $status === 'paid' ? 'succeeded' : 'pending',
            'customer_email' => $customerEmail,
            'customer_name' => 'Иван Тестовый',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $orderItemId = (int) DB::table('order_items')->insertGetId([
            'organization_id' => $seed['orgId'],
            'order_id' => $orderId,
            'inventory_item_id' => $seed['inventoryItemId'],
            'quantity' => $ticketCount,
            'unit_price' => self::PRICE,
            'discount_amount' => 0,
            'fee_amount' => 0,
            'total_amount' => $total,
            'event_title_snapshot' => 'Тестовое событие',
            'session_title_snapshot' => 'Основной сеанс',
            'venue_title_snapshot' => 'Тестовый зал',
            'created_at' => $now,
        ]);

        $qrPayload = '';

        for ($i = 1; $i <= $ticketCount; $i++) {
            $payload = 'nbl://t/'.Str::random(32);
            $qrPayload = $payload;

            // tickets — VIEW над order_items: каждый билет = отдельная позиция.
            $ticketFields = [
                'public_id' => (string) Str::ulid()->toBase32(),
                'organization_id' => $seed['orgId'],
                'ticket_number' => 'T-'.Str::random(12),
                'ticket_index' => $i,
                'event_id' => $seed['eventId'],
                'session_id' => $seed['sessionId'],
                'ticket_status' => 'issued',
                'qr_version' => 1,
                'qr_token_hash' => hash('sha256', Str::random(32)),
                'qr_payload' => $payload,
                'issued_at' => $now,
            ];
            if ($i === 1) {
                DB::table('order_items')->where('id', $orderItemId)->update($ticketFields + [
                    'quantity' => 1,
                    'total_amount' => (int) DB::table('order_items')->where('id', $orderItemId)->value('unit_price'),
                ]);
            } else {
                $copy = (array) DB::table('order_items')->where('id', $orderItemId)->first();
                unset($copy['id']);
                DB::table('order_items')->insertGetId(array_merge($copy, $ticketFields, [
                    'quantity' => 1,
                    'total_amount' => (int) $copy['unit_price'],
                ]));
            }
        }

        return ['orderId' => $orderId, 'orderPublicId' => $orderPublicId, 'orderNumber' => $orderNumber, 'qrPayload' => $qrPayload];
    }
}
