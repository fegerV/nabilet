<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

final class AchievementsTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private function buyer(): int
    {
        return DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(), 'email' => Str::random(10).'@example.test',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admission(int $userId, string $status = 'used'): int
    {
        [$org, $event, $session, $inventory] = $this->seedSellableSeat(available: 5);
        $order = DB::table('orders')->insertGetId([
            'public_id' => (string) Str::ulid(), 'order_number' => 'CLUB-'.Str::random(12),
            'organization_id' => $org, 'user_id' => $userId, 'subtotal_amount' => 5000000,
            'total_amount' => 5000000, 'currency' => 'RUB', 'status' => 'paid',
            'payment_status' => 'succeeded', 'customer_email' => 'club@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('order_items')->insertGetId([
            'organization_id' => $org, 'order_id' => $order, 'inventory_item_id' => $inventory,
            'quantity' => 1, 'unit_price' => 5000000, 'total_amount' => 5000000,
            'event_title_snapshot' => 'Concert', 'created_at' => now(),
            'public_id' => (string) Str::ulid(), 'ticket_number' => 'CLUB-'.Str::random(12),
            'ticket_index' => 1, 'event_id' => $event, 'session_id' => $session,
            'ticket_status' => $status, 'qr_version' => 1,
            'qr_token_hash' => hash('sha256', Str::random(16)), 'issued_at' => now(),
            'used_at' => $status === 'used' ? now() : null,
        ]);
    }

    private function collection(int $user): array
    {
        $token = app(SessionIssuer::class)->issue($user);
        return $this->getJson('/api/v1/me/achievements', ['Authorization' => 'Bearer '.$token])
            ->assertOk()->json('data');
    }

    public function test_requires_auth_and_empty_account_has_no_awards(): void
    {
        $this->getJson('/api/v1/me/achievements')->assertUnauthorized();
        $data = $this->collection($this->buyer());
        $this->assertSame(0, $data['visits']);
        $this->assertCount(15, $data['achievements']);
        $this->assertSame(5, $data['rules']['accrual_percent']);
        $this->assertNotEmpty($data['levels']);
    }

    public function test_only_owned_admissions_count_and_read_does_not_issue_rewards(): void
    {
        $buyer = $this->buyer();
        $other = $this->buyer();
        $ticket = $this->admission($buyer);
        $this->admission($buyer, 'issued');
        $this->admission($other);
        $before = DB::table('bonus_transactions')->count();
        $data = $this->collection($buyer);
        $this->assertSame(1, $data['visits']);
        $this->assertSame('earned', $data['achievements'][0]['status']);
        $this->assertSame($data, $this->collection($buyer));
        $this->assertSame($before, DB::table('bonus_transactions')->count());
        // A revoked admission no longer qualifies even if a timestamp remains.
        DB::table('order_items')->where('id', $ticket)->update(['ticket_status' => 'revoked', 'revoked_at' => now()]);
        $this->assertSame(0, $this->collection($buyer)['visits']);
    }

    public function test_group_tickets_at_one_session_count_once(): void
    {
        $buyer = $this->buyer();
        $ticket = $this->admission($buyer);
        $copy = (array) DB::table('order_items')->where('id', $ticket)->first();
        unset($copy['id']);
        $copy['public_id'] = (string) Str::ulid();
        $copy['ticket_number'] = 'CLUB-'.Str::random(12);
        $copy['ticket_index'] = 2;
        $copy['qr_token_hash'] = hash('sha256', Str::random(16));
        DB::table('order_items')->insert($copy);
        $this->assertSame(1, $this->collection($buyer)['visits']);
    }
}
