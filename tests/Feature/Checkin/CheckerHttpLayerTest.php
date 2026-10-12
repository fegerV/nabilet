<?php

declare(strict_types=1);

namespace Tests\Feature\Checkin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nabilet\Modules\Checkin\Services\DeviceTokenIssuer;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Tickets\Models\CheckinDevice;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Services\TicketScanService;
use Nabilet\Modules\Tickets\Services\TicketService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * `/api/v1/checkin/{validate,use,sync}` — устройства контроля входа.
 *
 * ЧТО БЫЛО НЕ ТАК
 *   У модуля Checkin не было HTTP-слоя вообще: три пути контракта
 *   (`openapi.yaml`, security `checkerAuth`) отвечали 404, а погашение жило в
 *   `POST /tickets/checkin/scan` за сессией сотрудника. Колонка
 *   `checkin_devices.device_token_hash` существовала, но её никто не писал и не
 *   читал — токен устройства нельзя было ни выдать, ни проверить. Фронтенд при
 *   этом уже объявлял `CheckinResult` из десяти значений
 *   (`resources/js/lib/types.ts`): контракт был написан, backend — нет.
 *
 * ЧТО ПРОВЕРЯЕТ ЭТОТ ФАЙЛ
 *   Идентификацию устройства (fail-closed: нет токена / неизвестный / отозван),
 *   исходы `validate` без записи, идемпотентность `use` по `client_scan_id`,
 *   сверку офлайн-сканов, выдачу и отзыв устройств, отчёт по сканам.
 */
class CheckerHttpLayerTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const QR = '11111111-2222-3333-4444-555555555555';
    private const SECOND_SCAN = '99999999-8888-7777-6666-555555555555';

    /** Токены устройств: в модели намеренно нет — там только хеш. */
    private array $deviceTokens = [];

    private DeviceTokenIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issuer = $this->app->make(DeviceTokenIssuer::class);
    }

    /* ------------------------------------------------------------------ */
    /* Идентификация                                                      */
    /* ------------------------------------------------------------------ */

    public function test_a_request_without_a_device_token_is_unauthorized(): void
    {
        [, , $sessionId] = $this->seedSellableSeat(available: 1);

        $this->postJson('/api/v1/checkin/validate', [
            'qr_payload' => 'NB1.anything',
            'session_id' => $sessionId,
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_an_unknown_device_token_is_refused(): void
    {
        [, , $sessionId] = $this->seedSellableSeat(available: 1);

        $this->withToken('nbck_this-token-was-never-issued')
            ->postJson('/api/v1/checkin/validate', [
                'qr_payload' => 'NB1.anything',
                'session_id' => $sessionId,
            ])->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_DEVICE_TOKEN');
    }

    /**
     * Отзыв обязан быть слышимым СРАЗУ: токен остаётся той же строкой, но
     * перестаёт пропускать. Обратное правило («всё, кроме revoked») пустило бы
     * внутрь устройство в статусе, которого никто не recognizeет.
     */
    public function test_a_revoked_device_is_refused_even_with_a_valid_token(): void
    {
        [$organizationId, , $sessionId] = $this->seedSellableSeat(available: 1);

        $device = $this->issueDevice($organizationId);
        $this->issuer->revoke($device);

        $this->withToken($this->tokenOf($device))
            ->postJson('/api/v1/checkin/validate', [
                'qr_payload' => 'NB1.anything',
                'session_id' => $sessionId,
            ])->assertStatus(401)
            ->assertJsonPath('error.code', 'DEVICE_NOT_ACTIVE');
    }

    /* ------------------------------------------------------------------ */
    /* validate                                                           */
    /* ------------------------------------------------------------------ */

    public function test_validate_reports_a_valid_ticket_without_consuming_it(): void
    {
        [$organizationId, , $sessionId, , $ticket] = $this->seedTicket();

        $this->withToken($this->tokenOf($this->issueDevice($organizationId)))
            ->postJson('/api/v1/checkin/validate', [
                'qr_payload' => $ticket->qr_payload,
                'session_id' => $sessionId,
            ])->assertOk()
            ->assertJsonPath('data.result', 'valid');

        // Билет выдан, но НЕ погашен: validate ничего не меняет и не пишет.
        $this->assertSame('issued', (string) $ticket->fresh()->status);
        $this->assertDatabaseCount('ticket_scans', 0);
    }

    public function test_validate_reports_a_forged_qr_as_invalid_signature(): void
    {
        [$organizationId, , $sessionId] = $this->seedSellableSeat(available: 1);

        $this->withToken($this->tokenOf($this->issueDevice($organizationId)))
            ->postJson('/api/v1/checkin/validate', [
                'qr_payload' => 'NB1.some-ticket.forged.signature',
                'session_id' => $sessionId,
            ])->assertOk()
            ->assertJsonPath('data.result', 'invalid_signature');
    }

    /**
     * Билет другого МЕРОПРИЯТИЯ — не то же самое, что билет другого сеанса.
     * Контракт различает `wrong_session` и `wrong_event`, и различие берётся из
     * сравнения event_id, а не из домена, который оба случая назвал
     * `wrong_session`. Поэтому в фикстуре ДВА сеанса одного события.
     */
    public function test_validate_distinguishes_wrong_event_from_wrong_session(): void
    {
        // Один билет сеанса 1 (событие E1). Различие проверяем, сканируя его на
        // сеансе того же события (=> wrong_session) и на сеансе другого события
        // (=> wrong_event) — отдельного билета на второй сеанс не нужно.
        [$organizationId, , $sessionId, , $ticket] = $this->seedTicket();
        [$sameEventSessionId] = $this->makeSecondSessionAndInventory($organizationId, $sessionId);

        $this->withToken($this->tokenOf($this->issueDevice($organizationId)))
            ->postJson('/api/v1/checkin/validate', [
                'qr_payload' => $ticket->qr_payload,
                'session_id' => $sameEventSessionId,
            ])->assertOk()
            ->assertJsonPath('data.result', 'wrong_session');

        // Билет первого сеанса на скане чужого мероприятия.
        [, , $otherSessionId] = $this->seedSellableSeat(available: 1, eventTitle: 'Другое событие');

        $this->withToken($this->tokenOf($this->issueDevice($organizationId)))
            ->postJson('/api/v1/checkin/validate', [
                'qr_payload' => $ticket->qr_payload,
                'session_id' => $otherSessionId,
            ])->assertOk()
            ->assertJsonPath('data.result', 'wrong_event');

        // Защита от случайной перепутанности: сеансы разного события и правда
        // разные, иначе wrong_event никогда бы не сработал.
        $this->assertNotSame($sessionId, $sameEventSessionId);
    }

    /* ------------------------------------------------------------------ */
    /* use                                                                */
    /* ------------------------------------------------------------------ */

    public function test_use_consumes_the_ticket_and_records_the_device(): void
    {
        [$organizationId, , $sessionId, , $ticket] = $this->seedTicket();
        $device = $this->issueDevice($organizationId);

        $this->withToken($this->tokenOf($device))
            ->postJson('/api/v1/checkin/use', [
                'qr_payload' => $ticket->qr_payload,
                'session_id' => $sessionId,
                'client_scan_id' => self::QR,
            ])->assertOk()
            ->assertJsonPath('data.result', 'valid');

        $this->assertSame('used', (string) $ticket->fresh()->status);
        $this->assertDatabaseHas('ticket_scans', [
            'ticket_id' => $ticket->id,
            'device_id' => $device->id,
            'client_scan_id' => self::QR,
            'result' => 'admitted',
        ]);
    }

    /**
     * Повтор того же `client_scan_id` отвечает ЗАПИСАННЫМ исходом и 409: база
     * держит уникальность пары (device_id, client_scan_id), поэтому «нажал
     * дважды» не может стать «вошло два человека».
     */
    public function test_a_replayed_use_returns_the_recorded_outcome(): void
    {
        [$organizationId, , $sessionId, , $ticket] = $this->seedTicket();
        $device = $this->issueDevice($organizationId);

        $body = [
            'qr_payload' => $ticket->qr_payload,
            'session_id' => $sessionId,
            'client_scan_id' => self::QR,
        ];

        $this->withToken($this->tokenOf($device))
            ->postJson('/api/v1/checkin/use', $body)
            ->assertOk();

        $this->withToken($this->tokenOf($device))
            ->postJson('/api/v1/checkin/use', $body)
            ->assertStatus(409)
            ->assertJsonPath('data.result', 'already_used');

        $this->assertDatabaseCount('ticket_scans', 1);
    }

    /**
     * Новый `client_scan_id` по уже погашенному билету — это отказ
     * (`already_used`, §32), а не второй допуск. Оба скана остаются в истории:
     * второй — доказательство попытки, а не молчание.
     */
    public function test_a_second_scan_with_a_new_key_reports_already_used(): void
    {
        [$organizationId, , $sessionId, , $ticket] = $this->seedTicket();
        $device = $this->issueDevice($organizationId);

        $this->withToken($this->tokenOf($device))
            ->postJson('/api/v1/checkin/use', [
                'qr_payload' => $ticket->qr_payload,
                'session_id' => $sessionId,
                'client_scan_id' => self::QR,
            ])->assertOk();

        $this->withToken($this->tokenOf($device))
            ->postJson('/api/v1/checkin/use', [
                'qr_payload' => $ticket->qr_payload,
                'session_id' => $sessionId,
                'client_scan_id' => self::SECOND_SCAN,
            ])->assertStatus(409)
            ->assertJsonPath('data.result', 'already_used');

        $this->assertDatabaseCount('ticket_scans', 2);
    }

    /* ------------------------------------------------------------------ */
    /* sync                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Сверка офлайн-сканов: устройство присылает то, что решило у двери.
     * Неизвестный билет — `rejected`, и он не роняет остальные строки пакета.
     */
    public function test_sync_records_offline_scans_and_reports_counters(): void
    {
        [$organizationId, , $sessionId, , $ticket] = $this->seedTicket();
        $device = $this->issueDevice($organizationId);

        $this->seedLiveBundle($device, $sessionId);

        $this->withToken($this->tokenOf($device))
            ->postJson('/api/v1/checkin/sync', [
                'device_id' => $device->public_id,
                'session_id' => $sessionId,
                'scans' => [
                    [
                        'client_scan_id' => self::QR,
                        'ticket_id' => $ticket->public_id,
                        'scanned_at' => now()->subHour()->toAtomString(),
                        'result' => 'valid',
                        'mode' => 'offline',
                    ],
                    [
                        'client_scan_id' => self::SECOND_SCAN,
                        'ticket_id' => 'does-not-exist',
                        'scanned_at' => now()->subHour()->toAtomString(),
                        'result' => 'unknown_ticket',
                        'mode' => 'offline',
                    ],
                ],
            ])->assertOk()
            ->assertJsonPath('data.accepted', 1)
            ->assertJsonPath('data.rejected', 1)
            ->assertJsonPath('data.duplicates', 0);

        $this->assertSame('used', (string) $ticket->fresh()->status);

        $scan = DB::table('ticket_scans')->where('client_scan_id', self::QR)->first();
        $this->assertSame('offline_sync', (string) $scan->mode);
        $this->assertSame($device->id, (int) $scan->device_id);
    }

    /**
     * Устройство A не может писать сканы от имени устройства B: `device_id` в
     * теле сверяется с тем, который предъявил токен.
     */
    public function test_sync_refuses_a_device_id_that_is_not_the_authenticated_device(): void
    {
        [$organizationId, , $sessionId] = $this->seedSellableSeat(available: 1);
        $device = $this->issueDevice($organizationId);
        $other = $this->issueDevice($organizationId, 'Другое устройство');

        // `scans` должен пройти валидацию (непустой массив), чтобы контроллер
        // дошёл до `assertSameDevice` — само содержимое скана не обрабатывается,
        // потому что проверка устройства срабатывает ДО цикла по сканам.
        $this->withToken($this->tokenOf($device))
            ->postJson('/api/v1/checkin/sync', [
                'device_id' => $other->public_id,
                'session_id' => $sessionId,
                'scans' => [
                    [
                        'client_scan_id' => self::QR,
                        'ticket_id' => 'does-not-exist',
                        'scanned_at' => now()->subHour()->toAtomString(),
                        'result' => 'valid',
                        'mode' => 'offline',
                    ],
                ],
            ])->assertStatus(403)
            ->assertJsonPath('error.code', 'DEVICE_MISMATCH');
    }

    /* ------------------------------------------------------------------ */
    /* Админская часть                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Выдача устройства — единственный момент, когда токен виден в ответе.
     * В базе только sha256; повторно его получить нельзя.
     */
    public function test_admin_issues_a_device_whose_token_is_stored_as_a_hash(): void
    {
        [$organizationId] = $this->seedSellableSeat(available: 1);

        $this->actingAsStaff($organizationId);

        $response = $this->postJson('/api/v1/admin/checkin/devices', [
            'organization_id' => $organizationId,
            'name' => 'Входная группа',
        ])->assertStatus(201);

        $token = (string) $response->json('data.token');
        $deviceId = (int) $response->json('data.device.id');

        $this->assertStringStartsWith(DeviceTokenIssuer::TOKEN_PREFIX, $token);
        $this->assertSame(
            hash('sha256', $token),
            (string) DB::table('checkin_devices')->where('id', $deviceId)->value('device_token_hash')
        );
    }

    public function test_admin_can_list_devices_and_revoke_one(): void
    {
        [$organizationId] = $this->seedSellableSeat(available: 1);

        $this->actingAsStaff($organizationId);

        $device = $this->issueDevice($organizationId);

        $this->getJson('/api/v1/admin/checkin/devices')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Входная группа']);

        // Токена в выдаче списка быть не должно — ни в каком виде.
        $body = $this->getJson('/api/v1/admin/checkin/devices')->assertOk()->getContent();
        $this->assertStringNotContainsString($this->tokenOf($device), (string) $body);

        $this->patchJson('/api/v1/admin/checkin/devices/' . $device->public_id, ['status' => 'revoked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertSame('revoked', (string) $device->fresh()->status);
    }

    public function test_a_report_lists_scans_with_their_devices(): void
    {
        [$organizationId, , $sessionId, , $ticket] = $this->seedTicket();
        $device = $this->issueDevice($organizationId);

        app(TicketScanService::class)->scan(
            ticketId: 0,
            sessionId: $sessionId,
            deviceId: $device->id,
            qrPayload: $ticket->qr_payload,
        );

        $this->actingAsStaff($organizationId);

        $this->getJson('/api/v1/admin/checkin/scans?session_id=' . $sessionId)
            ->assertOk()
            ->assertJsonPath('data.0.result', 'admitted')
            ->assertJsonPath('data.0.device.id', $device->id);
    }

    /* ------------------------------------------------------------------ */
    /* Фикстуры                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Мероприятие, сеанс, склад и выпущенный билет.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: Ticket}
     */
    private function seedTicket(): array
    {
        [$organizationId, $eventId, $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1);

        return [
            $organizationId,
            $eventId,
            $sessionId,
            $inventoryItemId,
            $this->issueTicket($organizationId, $inventoryItemId),
        ];
    }

    /**
     * ВТОРОЙ сеанс того же события и склад под него — без него нельзя отличить
     * `wrong_session` от `wrong_event`, потому что единственный сеанс события
     * всегда «правильный» или «чужое мероприятие». Билет на этот сеанс в тесте не
     * нужен: различие проверяется сканированием билета первого сеанса на втором
     * (того же события) и на сеансе чужого события.
     *
     * @return array{0: int, 1: int}
     */
    private function makeSecondSessionAndInventory(int $organizationId, int $sessionId): array
    {
        $first = DB::table('sessions')->where('id', $sessionId)->first();
        $now = now()->toDateTimeString();

        // Тот же зал и та же версия схемы, что у первого сеанса: мероприятие
        // одно, сеансов два.
        $secondSessionId = (int) DB::table('sessions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'event_id' => (int) $first->event_id,
            'venue_id' => (int) $first->venue_id,
            'hall_id' => (int) $first->hall_id,
            'schema_version_id' => (int) $first->schema_version_id,
            'starts_at' => now()->addDays(8)->toDateTimeString(),
            'timezone' => 'Asia/Almaty',
            'status' => 'on_sale',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Своя стоячая зона в ТОЙ ЖЕ версии схемы: `ck_inventory_target` требует
        // ровно один якорь, а через одну зону два сеанса поделили бы capacity.
        $standingZoneId = $this->seedStandingZone((int) $first->schema_version_id);

        $secondInventoryId = (int) DB::table('inventory_items')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'session_id' => $secondSessionId,
            'type' => 'standing',
            'standing_zone_id' => $standingZoneId,
            'price_amount' => 5_000_000,
            'currency' => 'RUB',
            'capacity' => 1,
            'available_quantity' => 1,
            'status' => 'available',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$secondSessionId, $secondInventoryId];
    }

    /**
     * Заказ → оплата → выпуск билетов: тот же путь, что и у покупателя, чтобы
     * `qr_payload` был подписан тем же секретом, каким его проверит контроллер.
     */
    private function issueTicket(int $organizationId, int $inventoryItemId): Ticket
    {
        $orders = app(OrderService::class);

        $order = $orders->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryItemId, 'quantity' => 1]],
        ]);

        // Реальный путь покупки: pending -> awaiting_payment -> paid. Машина
        // заказов запрещает pending -> paid напрямую, поэтому проходим через
        // awaitings_payment, иначе выпуск билетов падает с InvalidStateTransition.
        $orders->markAwaitingPayment($order);
        $orders->markPaid($order->fresh());

        $tickets = app(TicketService::class)->issueTicketsForOrder($order->fresh());

        return $tickets[0];
    }

    private function issueDevice(int $organizationId, string $name = 'Входная группа'): CheckinDevice
    {
        $issued = $this->issuer->issue($organizationId, $name);

        // Токен держим рядом с тестом, а не в модели: в `checkin_devices` его нет
        // by design, и подвешивать его на Eloquent значило бы врать о схеме.
        $this->deviceTokens[$issued['device']->id] = $issued['token'];

        return $issued['device'];
    }

    private function tokenOf(CheckinDevice $device): string
    {
        return $this->deviceTokens[$device->id];
    }

    private function seedLiveBundle(CheckinDevice $device, int $sessionId): void
    {
        $session = DB::table('sessions')->where('id', $sessionId)->first();

        // Колонка списка билетов в `offline_bundles` называется `payload_json`,
        // а не `tickets_json`; кроме того таблица требует NOT NULL
        // organization_id / event_id / generated_at / created_at / updated_at.
        DB::table('offline_bundles')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => (int) $device->organization_id,
            'checkin_device_id' => $device->id,
            'event_id' => (int) $session->event_id,
            'session_id' => $sessionId,
            'bundle_hash' => (string) Str::random(32),
            'schema_version' => 1,
            'payload_json' => json_encode([]),
            'status' => 'generated',
            'generated_at' => now()->toDateTimeString(),
            'expires_at' => now()->addDay()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Сотрудник: `StaffRole::isStaff()` читает `user_roles`, а не колонку в
     * `users` — роль выдаётся записью в связи.
     */
    private function actingAsStaff(int $organizationId): string
    {
        $now = now()->toDateTimeString();

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'operator-' . Str::random(6) . '@example.test',
            'password' => Hash::make('Kv7#mRta2!Qz'),
            'first_name' => 'Оператор',
            'last_name' => 'Входа',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        $this->actingAs(User::query()->findOrFail($userId), 'api');

        return (string) $roleId;
    }
}
