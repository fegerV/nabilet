<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Kiosk\Domain\KioskDevice as DevicePolicy;
use Nabilet\Modules\Kiosk\Domain\KioskSale as SalePolicy;
use Nabilet\Modules\Kiosk\Models\KioskDevice;
use Nabilet\Modules\Kiosk\Models\KioskSale;
use Nabilet\Tests\Support\StaffAuth;
use Tests\TestCase;

/**
 * Админка и устройственный API киосков.
 *
 * Здесь проверяется не только CRUD: главный риск киоска — что выключение
 * продаж не отключило устройство полностью (иначе админ видит «мертвый»
 * киоск и не может управлять обновлением), и что ротация/отзыв действительно
 * убивают старый токен.
 */
final class KioskDeviceTest extends TestCase
{
    use RefreshDatabase;
    use StaffAuth;

    private function organization(int $suffix = 1): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Организация ' . $suffix,
            'slug' => 'kiosk-org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array{int, string} */
    private function createKiosk(int $organizationId, array $extra = []): array
    {
        $this->actingAsStaff($organizationId);

        $response = $this->postJson('/api/v1/admin/kiosks', array_merge([
            'organization_id' => $organizationId,
            'name' => 'Киоск у входа',
            'location_address' => 'ул. Центральная, 1',
        ], $extra))->assertStatus(201);

        $device = $response->json('data.device');
        $token = (string) $response->json('data.token');

        return [(int) $device['id'], $token];
    }

    public function test_admin_creates_kiosk_with_token_shown_once(): void
    {
        $organizationId = $this->organization();
        $this->actingAsStaff($organizationId);

        $response = $this->postJson('/api/v1/admin/kiosks', [
            'organization_id' => $organizationId,
            'name' => 'Касса в фойе',
            'location_address' => 'ул. Ленина, 10',
            'location_note' => '2-й этаж',
        ])->assertStatus(201);

        $token = (string) $response->json('data.token');
        $this->assertStringStartsWith('nbks_', $token);
        $this->assertSame(hash('sha256', $token), $response->json('data.device.kiosk_token_hash'));
        $this->assertFalse((bool) $response->json('data.device.sales_enabled'));
        $this->assertSame('ул. Ленина, 10', $response->json('data.device.location_address'));

        $list = $this->getJson('/api/v1/admin/kiosks')->assertOk();
        $this->assertStringNotContainsString($token, $list->getContent());
        $this->assertSame(1, $list->json('summary.devices.total'));
    }

    public function test_admin_enables_and_disables_sales_without_revoking_device(): void
    {
        $organizationId = $this->organization();
        [$deviceId, $token] = $this->createKiosk($organizationId);

        $this->patchJson('/api/v1/admin/kiosks/' . $deviceId, ['sales_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.sales_enabled', true);

        // Киоск с включёнными продажами видит флаг в heartbeat.
        $this->withToken($token)->postJson('/api/v1/kiosk/heartbeat', [])
            ->assertOk()
            ->assertJsonPath('data.sales_enabled', true);

        $this->patchJson('/api/v1/admin/kiosks/' . $deviceId, ['sales_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.sales_enabled', false);

        // Устройство осталось на связи: токен работает, просто продажи выключены.
        $this->withToken($token)->postJson('/api/v1/kiosk/heartbeat', [])
            ->assertOk()
            ->assertJsonPath('data.sales_enabled', false)
            ->assertJsonPath('data.status', DevicePolicy::STATUS_ACTIVE);
    }

    public function test_disabled_device_token_is_refused_fail_closed(): void
    {
        $organizationId = $this->organization();
        [$deviceId, $token] = $this->createKiosk($organizationId, ['sales_enabled' => true]);

        $this->patchJson('/api/v1/admin/kiosks/' . $deviceId, ['status' => DevicePolicy::STATUS_DISABLED])
            ->assertOk()
            ->assertJsonPath('data.status', DevicePolicy::STATUS_DISABLED)
            ->assertJsonPath('data.sales_enabled', false);

        $this->withToken($token)->getJson('/api/v1/kiosk/config')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'KIOSK_NOT_ACTIVE');
    }

    public function test_missing_unknown_and_revoked_tokens_are_refused(): void
    {
        $this->postJson('/api/v1/kiosk/heartbeat', [])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->withToken('nbks_unknown')->postJson('/api/v1/kiosk/heartbeat', [])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_KIOSK_TOKEN');

        $organizationId = $this->organization();
        [$deviceId, $token] = $this->createKiosk($organizationId);

        $this->postJson('/api/v1/admin/kiosks/' . $deviceId . '/revoke')->assertOk();

        $this->withToken($token)->postJson('/api/v1/kiosk/heartbeat', [])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'KIOSK_NOT_ACTIVE');
    }

    public function test_revoked_kiosk_cannot_be_reactivated_by_patch(): void
    {
        $organizationId = $this->organization();
        [$deviceId, $token] = $this->createKiosk($organizationId);

        $this->postJson('/api/v1/admin/kiosks/' . $deviceId . '/revoke')->assertOk();

        $this->patchJson('/api/v1/admin/kiosks/' . $deviceId, ['status' => DevicePolicy::STATUS_ACTIVE])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->patchJson('/api/v1/admin/kiosks/' . $deviceId, ['sales_enabled' => true])
            ->assertStatus(422);

        $this->withToken($token)->postJson('/api/v1/kiosk/heartbeat', [])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'KIOSK_NOT_ACTIVE');
    }

    public function test_rotate_token_invalidates_old_one(): void
    {
        $organizationId = $this->organization();
        [$deviceId, $oldToken] = $this->createKiosk($organizationId);

        $response = $this->postJson('/api/v1/admin/kiosks/' . $deviceId . '/rotate-token')
            ->assertStatus(201);
        $newToken = (string) $response->json('data.token');

        $this->assertNotSame($oldToken, $newToken);
        $this->assertStringStartsWith('nbks_', $newToken);

        $this->withToken($oldToken)->postJson('/api/v1/kiosk/heartbeat', [])
            ->assertStatus(401);
        $this->withToken($newToken)->postJson('/api/v1/kiosk/heartbeat', [])
            ->assertOk();
    }

    public function test_remote_update_directive_and_result(): void
    {
        $organizationId = $this->organization();
        [$deviceId, $token] = $this->createKiosk($organizationId);

        $checksum = str_repeat('a', 64);
        $this->postJson('/api/v1/admin/kiosks/' . $deviceId . '/update', [
            'target_version' => '1.2.3',
            'update_url' => 'https://example.test/kiosk-1.2.3.zip',
            'update_checksum' => $checksum,
        ])->assertOk()->assertJsonPath('data.update_status', DevicePolicy::UPDATE_PENDING);

        $config = $this->withToken($token)->getJson('/api/v1/kiosk/config')->assertOk();
        $this->assertSame('1.2.3', $config->json('data.update.target_version'));
        $this->assertSame($checksum, $config->json('data.update.checksum'));

        $this->withToken($token)->postJson('/api/v1/kiosk/update-result', [
            'status' => DevicePolicy::UPDATE_APPLIED,
            'version' => '1.2.3',
        ])->assertOk();

        $device = KioskDevice::query()->findOrFail($deviceId);
        $this->assertSame(DevicePolicy::UPDATE_APPLIED, $device->update_status);
        $this->assertSame('1.2.3', $device->update_applied_version);

        // Повторный результат без новой директивы — конфликт, а не тихий success.
        $this->withToken($token)->postJson('/api/v1/kiosk/update-result', [
            'status' => DevicePolicy::UPDATE_APPLIED,
            'version' => '1.2.3',
        ])->assertStatus(409)->assertJsonPath('error.code', 'KIOSK_UPDATE_NOT_PENDING');
    }

    public function test_update_can_be_cancelled(): void
    {
        $organizationId = $this->organization();
        [$deviceId, $token] = $this->createKiosk($organizationId);

        $this->postJson('/api/v1/admin/kiosks/' . $deviceId . '/update', [
            'target_version' => '1.2.3',
            'update_url' => 'https://example.test/kiosk.zip',
        ])->assertOk();

        $this->postJson('/api/v1/admin/kiosks/' . $deviceId . '/update/cancel')
            ->assertOk()
            ->assertJsonPath('data.update_status', DevicePolicy::UPDATE_NONE);

        $this->withToken($token)->getJson('/api/v1/kiosk/config')
            ->assertOk()
            ->assertJsonPath('data.update', null);
    }

    public function test_analytics_counts_online_and_sales(): void
    {
        $organizationId = $this->organization();
        [$deviceId, $token] = $this->createKiosk($organizationId, ['sales_enabled' => true]);

        $this->withToken($token)->postJson('/api/v1/kiosk/heartbeat', [])->assertOk();

        KioskSale::query()->create([
            'client_sale_id' => (string) Str::uuid(),
            'status' => SalePolicy::STATUS_ISSUED,
            'kiosk_device_id' => $deviceId,
            'organization_id' => $organizationId,
            'amount_minor' => 500000,
        ]);

        $stats = $this->getJson('/api/v1/admin/kiosks/analytics')->assertOk()->json('data');
        $this->assertSame(1, $stats['devices']['total']);
        $this->assertSame(1, $stats['devices']['selling']);
        $this->assertSame(1, $stats['devices']['online']);
        $this->assertSame(1, $stats['today']['sales']);
        $this->assertSame(500000, $stats['today']['revenue_minor']);
    }

    public function test_foreign_organization_kiosk_is_invisible(): void
    {
        $mine = $this->organization(1);
        $theirs = $this->organization(2);
        [$foreignId] = $this->createKiosk($theirs);

        $this->actingAsStaff($mine);
        $this->getJson('/api/v1/admin/kiosks/' . $foreignId)->assertStatus(404);
        $this->getJson('/api/v1/admin/kiosks')->assertOk()->assertJsonCount(0, 'data');
    }
}
