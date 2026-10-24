<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Database\IdentifierLookup;
use Nabilet\Core\Support\DatabaseId;
use Nabilet\Modules\Checkin\Services\DeviceTokenIssuer;
use Nabilet\Modules\Tickets\Models\CheckinDevice;
use Tests\TestCase;

/**
 * «Число — это id, всё остальное — public_id»: один владелец запроса.
 *
 * Правило стояло в трёх местах, и два из них — побайтово одинаковые приватные
 * методы `find()` / `findDevice()` в двух контроллерах Checkin. Тесты фиксируют
 * не только «работает», но и обратные случаи: строку, которая ПОХОЖА на число,
 * и число, которое СЛИШКОМ длинное для int.
 */
class IdentifierLookupTest extends TestCase
{
    use RefreshDatabase;

    private DeviceTokenIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issuer = app(DeviceTokenIssuer::class);
    }

    public function testResolvesANumericIdentifierByPrimaryKey(): void
    {
        $device = $this->issueDevice();

        $found = IdentifierLookup::first(CheckinDevice::class, (string) $device->id);

        $this->assertNotNull($found);
        $this->assertSame($device->id, $found->id);
    }

    public function testResolvesAPublicIdentifierByPublicId(): void
    {
        $device = $this->issueDevice();

        $found = IdentifierLookup::first(CheckinDevice::class, $device->public_id);

        $this->assertNotNull($found);
        $this->assertSame($device->id, $found->id);
    }

    public function testAnUnknownIdentifierIsNullRatherThanAnException(): void
    {
        $this->assertNull(IdentifierLookup::first(CheckinDevice::class, '999999'));
        $this->assertNull(IdentifierLookup::first(CheckinDevice::class, 'nbck_nosuchdevice'));
    }

    /**
     * `'012abc'` — public_id, а НЕ id 12.
     *
     * `intval('012abc')` даёт 12, и запрос ушёл бы к чужой записи. Fail-closed:
     * нераспознанное значение ищется как public_id и не находится.
     */
    public function testAStringThatOnlyLooksNumericIsNotCoerced(): void
    {
        $device = $this->issueDevice();

        $this->assertNull(IdentifierLookup::first(CheckinDevice::class, '012abc'));
        $this->assertSame($device->id, IdentifierLookup::first(CheckinDevice::class, (string) $device->id)?->id);
    }

    /**
     * Двадцать цифр — не id.
     *
     * `sessions.id` и остальные ключи `BIGINT UNSIGNED` (до 20 цифр), но `(int)`
     * на 64-битной платформе умеет 19. Строка из 20 цифр привелась бы с молчаливым
     * переполнением и уходила бы к ДРУГОЙ строке: не ошибка, а чтение чужих данных.
     */
    public function testAnOverlongNumericStringIsNotTreatedAsAnId(): void
    {
        $twentyDigits = '12345678901234567890';

        $this->assertFalse(DatabaseId::isNumeric($twentyDigits));
        $this->assertNull(IdentifierLookup::first(CheckinDevice::class, $twentyDigits));
    }

    /** Правило одно на все сущности: тот же вызов для `Order` резолвит public_id. */
    public function testTheSameRuleWorksForAnotherEntity(): void
    {
        $orderId = (int) DB::table('orders')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'order_number' => 'ORD-' . Str::upper(Str::random(8)),
            'organization_id' => $this->createOrganization(),
            'status' => 'pending',
            'subtotal_amount' => 1000,
            'total_amount' => 1000,
            'currency' => 'RUB',
            'customer_email' => 'lookup@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $byPublicId = DB::table('orders')->where('id', $orderId)->value('public_id');

        $found = IdentifierLookup::first(\Nabilet\Modules\Orders\Models\Order::class, (string) $byPublicId);

        $this->assertNotNull($found);
        $this->assertSame($orderId, $found->id);
        $this->assertSame($orderId, IdentifierLookup::first(\Nabilet\Modules\Orders\Models\Order::class, (string) $orderId)?->id);
    }

    private function issueDevice(string $name = 'Входная группа'): CheckinDevice
    {
        return $this->issuer->issue($this->createOrganization(), $name)['device'];
    }

    private function createOrganization(): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Lookup Test Org',
            'slug' => 'lookup-test-' . Str::random(6),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
