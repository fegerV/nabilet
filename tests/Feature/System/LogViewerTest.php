<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * `GET /api/v1/admin/system/logs` — страница «Логи» в админке.
 *
 * ЧТО ЗДЕСЬ ЗАЩИЩАЕТСЯ
 *
 *  1. Запись, а не строка. Многострочный стектрейс обязан склеиваться в одну
 *     запись: иначе фильтр по уровню применяется к первой строке из двадцати,
 *     а остальные выглядят как независимые записи без даты.
 *  2. Фильтры работают на сервере. Поиск по `request_id` должен находить
 *     запись, даже если этот идентификатор есть только в контексте.
 *  3. Имя файла из запроса не выводит за пределы `storage/logs`: `../../.env`
 *     не должен читаться ни при каких условиях.
 *  4. Секреты не отдаются даже из старых записей. Фильтр канала появился
 *     позже самих логов, поэтому чтение обязано чистить текст повторно —
 *     иначе админка стала бы способом обойти фильтр.
 */
final class LogViewerTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'laravel-test-logviewer.log';

    /** @var list<string> файлы, созданные тестом: удаляются в tearDown */
    private array $written = [];

    /** Администратор создаётся один раз: в одном тесте `logs()` зовётся дважды. */
    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Имя начинается с `laravel`, чтобы попасть в тот же glob, что и
        // настоящие логи: проверяется реальный путь чтения, а не заглушка.
        $this->writeLog(self::FILE, implode("\n", [
            '[2026-10-22 10:00:00] production.ERROR: Оплата не прошла {"order_id":38,"request_id":"abc123def456"}',
            '#0 /app/Modules/Payments/Http/Controllers/PaymentController.php(88): handle()',
            '#1 {main}',
            '[2026-10-22 10:00:05] production.WARNING: Медленный запрос {"request_id":"fff999aaa111"}',
            '[2026-10-22 10:00:09] production.INFO: Гость открыл витрину',
            '[2026-10-22 10:00:11] production.ERROR: Вход в кабинет {"email":"buyer@example.test","password":"hunter2"}',
        ]) . "\n");
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function writeLog(string $name, string $contents): void
    {
        $path = storage_path('logs/' . $name);
        file_put_contents($path, $contents);
        $this->written[] = $path;
    }

    /** Администратор: пользователь + роль + членство в организации. */
    private function admin(): User
    {
        if ($this->admin !== null) {
            return $this->admin;
        }

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'surgut-logviewer-' . Str::random(6),
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'logs-admin@nabilet.local',
            'password' => bcrypt('Secret123456!'),
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
                'description' => 'Полный доступ',
            ]));

        DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => now()->toDateTimeString(),
        ]);

        // `EnsureAdminRole` смотрит роли из `user_roles` (`User::roles()`), а не
        // членство в организации: одно без другого админ-API не открывает.
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => now()->toDateTimeString(),
        ]);

        return $this->admin = User::query()->findOrFail($userId);
    }

    /** @return array<string, mixed> */
    private function logs(string $query = ''): array
    {
        $url = '/api/v1/admin/system/logs?file=' . self::FILE . ($query === '' ? '' : '&' . $query);

        $response = $this->actingAs($this->admin(), 'api')->getJson($url);
        $response->assertOk();

        return $response->json();
    }

    public function test_a_multiline_trace_stays_one_entry(): void
    {
        $payload = $this->logs();

        // Четыре записи, а не шесть строк: две строки трассы принадлежат
        // записи про оплату и не считаются отдельными записями.
        $this->assertSame(4, $payload['total_entries']);

        // Свежие первыми.
        $this->assertSame('ERROR', $payload['entries'][0]['level']);
        $this->assertSame('WARNING', $payload['entries'][2]['level']);
    }

    public function test_the_message_is_split_from_the_context(): void
    {
        $entry = $this->logs()['entries'][3];

        $this->assertSame('Оплата не прошла', $entry['message']);
        // Контекст отдаётся разложенным по строкам: `"order_id": 38`, а не
        // сплошной строкой на несколько килобайт.
        $this->assertStringContainsString('"order_id": 38', $entry['context']);
        $this->assertStringContainsString('#0 ', $entry['trace']);
        $this->assertSame('abc123def456', $entry['request_id']);
    }

    public function test_entries_are_filtered_by_level(): void
    {
        $payload = $this->logs('level=ERROR');

        $this->assertSame(2, $payload['matched']);

        foreach ($payload['entries'] as $entry) {
            $this->assertSame('ERROR', $entry['level']);
        }
    }

    public function test_search_matches_the_request_id_inside_the_context(): void
    {
        $payload = $this->logs('search=abc123');

        $this->assertSame(1, $payload['matched']);
        $this->assertSame('abc123def456', $payload['entries'][0]['request_id']);
    }

    public function test_pagination_does_not_repeat_entries(): void
    {
        $first = $this->logs('lines=2&offset=0');
        $second = $this->logs('lines=2&offset=2');

        $this->assertCount(2, $first['entries']);
        $this->assertCount(2, $second['entries']);

        $this->assertNotSame($first['entries'][0]['date'], $second['entries'][0]['date']);
        $this->assertSame('2026-10-22 10:00:00', $second['entries'][1]['date']);
    }

    public function test_a_file_outside_the_log_directory_is_refused(): void
    {
        $response = $this->actingAs($this->admin(), 'api')
            ->getJson('/api/v1/admin/system/logs?file=' . urlencode('../../.env'));

        $response->assertOk();
        $response->assertJsonPath('file', null);
        $response->assertJsonPath('entries', []);
    }

    public function test_secrets_are_scrubbed_on_read(): void
    {
        $body = (string) $this->actingAs($this->admin(), 'api')
            ->getJson('/api/v1/admin/system/logs?file=' . self::FILE)
            ->getContent();

        // Пароль и адрес лежат в файле открытым текстом (запись «до фильтра») —
        // в ответе их быть не должно.
        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('buyer@example.test', $body);
    }

    public function test_the_response_lists_available_log_files(): void
    {
        $payload = $this->logs();

        $names = array_column($payload['files'], 'name');

        $this->assertContains(self::FILE, $names);
        $this->assertContains('ERROR', $payload['levels']);
        $this->assertSame(2, $payload['levels_present']['ERROR']);
    }
}
