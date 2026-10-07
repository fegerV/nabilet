<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nabilet\Modules\Webhooks\Domain\RetryPolicy;
use Nabilet\Modules\Webhooks\Jobs\SendWebhookDeliveryJob;
use Nabilet\Modules\Webhooks\Services\WebhookDispatchService;
use Nabilet\Modules\Webhooks\Services\WebhookEndpointGuard;
use Tests\TestCase;

class OutboundWebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_exact_signed_json_and_marks_delivery_successful(): void
    {
        Http::fake(fn () => Http::response('accepted', 202));

        $organizationId = $this->createOrganization();
        $webhook = $this->createWebhook($organizationId, ['order.paid']);
        $service = app(WebhookDispatchService::class);

        self::assertSame(1, $service->dispatch('order.paid', [
            'order_id' => 42,
            'status' => 'paid',
        ], $organizationId));

        $delivery = WebhookDelivery::query()->firstOrFail();
        self::assertSame('order.paid', $delivery->event_name);

        app(SendWebhookDeliveryJob::class, ['deliveryId' => $delivery->id, 'jitter' => 0.5])
            ->handle(app(RetryPolicy::class), app(WebhookEndpointGuard::class));

        $delivery->refresh();
        self::assertSame(202, $delivery->status_code);
        self::assertNotNull($delivery->delivered_at);
        self::assertNull($delivery->next_retry_at);

        $recordedRequest = Http::recorded()->first();
        self::assertNotNull($recordedRequest);
        $request = $recordedRequest[0];
        $body = $request->body();
        $payload = json_decode($body, true);

        self::assertSame($webhook->url, $request->url());
        self::assertTrue($request->hasHeader('X-Nabilet-Event', 'order.paid'));
        self::assertTrue($request->hasHeader('X-Nabilet-Delivery', $delivery->delivery_id));
        self::assertTrue($request->hasHeader(
            'X-Nabilet-Signature',
            hash_hmac('sha256', $body, 'secret-for-test'),
        ));
        self::assertSame('order.paid', $payload['event'] ?? null);
        self::assertSame((int) $webhook->organization_id, $payload['organization_id'] ?? null);
        self::assertIsString($payload['occurred_at'] ?? null);
        self::assertEqualsCanonicalizing(['order_id' => 42, 'status' => 'paid'], $payload['data'] ?? null);
    }

    public function test_server_error_schedules_backoff_but_client_error_is_not_retried(): void
    {
        $organizationId = $this->createOrganization();
        $webhook = $this->createWebhook($organizationId, ['order.paid'], retryLimit: 3);
        $delivery = $this->newDelivery($webhook);

        Http::fake(fn () => Http::response('down', 503));
        app(SendWebhookDeliveryJob::class, ['deliveryId' => $delivery->id, 'jitter' => 0.5])
            ->handle(app(RetryPolicy::class), app(WebhookEndpointGuard::class));

        $delivery->refresh();
        self::assertSame(2, $delivery->attempt);
        self::assertNull($delivery->delivered_at);
        self::assertNotNull($delivery->next_retry_at);
        self::assertGreaterThan(now()->timestamp, $delivery->next_retry_at->timestamp);

    }

    public function test_client_error_is_not_retried(): void
    {
        $organizationId = $this->createOrganization();
        $webhook = $this->createWebhook($organizationId, ['order.paid'], retryLimit: 3);
        $delivery = $this->newDelivery($webhook);

        Http::fake(fn () => Http::response('bad request', 400));
        app(SendWebhookDeliveryJob::class, ['deliveryId' => $delivery->id, 'jitter' => 0.5])
            ->handle(app(RetryPolicy::class), app(WebhookEndpointGuard::class));

        $delivery->refresh();
        self::assertSame(400, $delivery->status_code);
        self::assertSame(1, $delivery->attempt);
        self::assertNull($delivery->next_retry_at);
        self::assertStringContainsString('4xx', (string) $delivery->error_message);
    }

    public function test_replaying_the_same_order_event_does_not_fan_out_duplicate_deliveries(): void
    {
        $organizationId = $this->createOrganization();
        $this->createWebhook($organizationId, ['order.paid']);
        $service = app(WebhookDispatchService::class);
        $eventId = (string) Str::uuid();

        self::assertSame(1, $service->dispatch(
            'order.paid',
            ['order_id' => 42],
            $organizationId,
            $eventId,
        ));
        self::assertSame(0, $service->dispatch(
            'order.paid',
            ['order_id' => 42],
            $organizationId,
            $eventId,
        ));

        self::assertSame(1, WebhookDelivery::query()->count());
        self::assertSame(1, DB::table('jobs')->count());
    }

    public function test_private_ip_endpoint_is_rejected_before_request(): void
    {
        $guard = app(WebhookEndpointGuard::class);

        $this->expectException(\Nabilet\Core\Errors\DomainRuleViolation::class);
        $guard->resolve('https://127.0.0.1/admin');
    }

    private function createOrganization(): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Webhook Test Org',
            'slug' => 'webhook-test-' . Str::random(6),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<string> $events */
    private function createWebhook(int $organizationId, array $events, int $retryLimit = 3): Webhook
    {
        return Webhook::query()->create([
            'organization_id' => $organizationId,
            'url' => 'https://example.com/hooks/nabilet',
            'secret_encrypted' => Crypt::encryptString('secret-for-test'),
            'events_json' => json_encode($events, JSON_THROW_ON_ERROR),
            'active' => true,
            'retry_limit' => $retryLimit,
        ]);
    }

    private function newDelivery(Webhook $webhook): WebhookDelivery
    {
        return WebhookDelivery::query()->create([
            'webhook_id' => $webhook->id,
            'delivery_id' => (string) Str::uuid(),
            'event_name' => 'order.paid',
            'payload_json' => ['event' => 'order.paid', 'data' => ['order_id' => 42]],
            'attempt' => 1,
            'created_at' => now(),
        ]);
    }
}
