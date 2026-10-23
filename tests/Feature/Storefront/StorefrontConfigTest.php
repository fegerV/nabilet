<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Storefront\Services\StorefrontService;
use Tests\TestCase;

/**
 * Конфиг витрины: нормализация, публичная выдача и админский путь.
 *
 * ЗАЧЕМ ЭТОТ ТЕСТ. `GET /storefront` — первый запрос каждого посетителя: из
 * него собираются тема, брендинг, шапка, подвал и порядок секций. Ошибка здесь
 * видна не в одном месте, а на всей афише сразу. До этого файла модуль
 * `Storefront` был единственным содержательным модулем без своего теста.
 *
 * ГЛАВНОЕ, ЧТО ЗАЩИЩАЕТСЯ, — НОРМАЛИЗАЦИЯ. Конфиг приходит из админки и уезжает
 * в браузер двумя путями: как CSS-переменные (`var(--brand)`) и как тексты,
 * которые Vue выводит интерполяцией. Поэтому `StorefrontService::normalize()`
 * работает по белому списку: цвета проверяются регуляркой `#rgb|#rrggbb`,
 * ссылки — схемой, числа — границами, перечисления — списком. Тест проверяет
 * именно отказ, а не согласие: строка `red; } html{display:none}` должна не
 * «как-то обработаться», а НЕ ПОПАСТЬ в вывод вообще. Утверждение строится на
 * поиске подстроки в сериализованном конфиге, потому что «поле равно дефолту»
 * прошло бы и в том случае, если бы инъекция уехала в соседний ключ.
 *
 * ЧТО НЕ ПРОВЕРЯЕТСЯ ЗДЕСЬ. Внешний вид витрины (как секции выглядят) — это
 * фронтенд; здесь только контракт данных.
 */
final class StorefrontConfigTest extends TestCase
{
    use RefreshDatabase;

    private function service(): StorefrontService
    {
        return $this->app->make(StorefrontService::class);
    }

    // ── Публичная выдача ──────────────────────────────────────────────────

    public function test_a_guest_gets_the_default_config_when_nothing_is_saved(): void
    {
        $data = $this->getJson('/api/v1/storefront')->assertOk()->json('data');

        self::assertSame(1, $data['version']);
        self::assertSame('violet', $data['theme']['preset']);
        self::assertSame('NABILET', $data['branding']['name']);

        // Порядок секций — часть контракта: витрина начинается с афиши, а не с
        // баннера. Это решение зафиксировано в `defaultConfig()` с объяснением,
        // и здесь оно защищено от случайной перестановки.
        self::assertSame(
            ['posters', 'categories', 'promo', 'stats', 'subscribe'],
            array_column($data['sections'], 'type'),
        );
    }

    public function test_a_guest_gets_the_config_of_the_primary_organization(): void
    {
        // Гость не знает организации. Показывать ему дефолт, когда организатор
        // уже настроил витрину, — это «настройки не сохранились» с точки зрения
        // администратора, поэтому контроллер берёт первую организацию.
        $organizationId = $this->organization('Главная площадка');

        $this->service()->save((string) $organizationId, [
            'branding' => ['name' => 'Сургут-Концерт'],
            'theme' => ['brand' => '#123456'],
        ]);

        $data = $this->getJson('/api/v1/storefront')->assertOk()->json('data');

        self::assertSame('Сургут-Концерт', $data['branding']['name']);
        self::assertSame('#123456', $data['theme']['brand']);
    }

    public function test_saving_through_the_admin_api_invalidates_the_public_cache(): void
    {
        $organizationId = $this->organization('Площадка');
        [$admin, $token] = $this->staff($organizationId, 'admin@example.test');

        // Первый запрос прогревает кеш дефолтом (60 секунд).
        self::assertSame('NABILET', $this->getJson('/api/v1/storefront')->json('data.branding.name'));

        $this->putJson('/api/v1/admin/storefront', [
            'config' => ['branding' => ['name' => 'Новое имя']],
        ], $this->headers($token))->assertOk();

        // Без явной инвалидации гость минуту видел бы старое имя — и это
        // выглядело бы как «сохранение не сработало».
        self::assertSame('Новое имя', $this->getJson('/api/v1/storefront')->json('data.branding.name'));
    }

    // ── Нормализация: отказы ──────────────────────────────────────────────

    public function test_a_colour_that_is_not_hex_never_reaches_the_output(): void
    {
        // Классическая css-инъекция: значение подставляется в `--brand`.
        $config = $this->service()->normalize([
            'theme' => ['brand' => 'red; } html{display:none} :root{'],
        ]);

        self::assertSame(
            StorefrontService::PRESETS['violet']['brand'],
            $config['theme']['brand'],
            'невалидный цвет должен замениться кистью пресета',
        );

        // Проверка по всему документу, а не по одному полю: инъекция не должна
        // найтись НИГДЕ, в том числе в соседнем ключе.
        self::assertStringNotContainsString('display:none', json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_valid_colour_is_kept_and_lowercased(): void
    {
        $config = $this->service()->normalize(['theme' => ['brand' => '#AABBCC', 'accent' => '#f0f']]);

        self::assertSame('#aabbcc', $config['theme']['brand']);
        self::assertSame('#f0f', $config['theme']['accent']);
    }

    public function test_numbers_are_clamped_to_their_documented_range(): void
    {
        $tooBig = $this->service()->normalize(['theme' => ['radius' => 999]]);
        $tooSmall = $this->service()->normalize(['theme' => ['radius' => -5]]);

        self::assertSame(28, $tooBig['theme']['radius']);
        self::assertSame(0, $tooSmall['theme']['radius']);

        $section = $this->service()->normalize([
            'sections' => [['type' => 'posters', 'settings' => ['columns' => 9, 'limit' => 999]]],
        ])['sections'][0];

        self::assertSame(4, $section['settings']['columns']);
        self::assertSame(60, $section['settings']['limit']);
    }

    public function test_unknown_enum_values_fall_back_to_defaults(): void
    {
        $config = $this->service()->normalize([
            'theme' => ['mode' => 'neon', 'surface' => 'glass', 'preset' => 'нет-такой'],
        ]);

        self::assertSame('auto', $config['theme']['mode']);
        self::assertSame('tinted', $config['theme']['surface']);
        self::assertSame('violet', $config['theme']['preset']);
    }

    public function test_unknown_keys_never_reach_the_output(): void
    {
        $config = $this->service()->normalize([
            'evilTop' => 'x',
            'theme' => ['brand' => '#000000', 'evil' => 'x'],
            'branding' => ['name' => 'Имя', 'adminNote' => 'внутренняя заметка'],
        ]);

        self::assertArrayNotHasKey('evilTop', $config);
        self::assertArrayNotHasKey('evil', $config['theme']);
        self::assertArrayNotHasKey('adminNote', $config['branding']);
        self::assertSame('Имя', $config['branding']['name']);
    }

    public function test_a_logo_url_with_a_dangerous_scheme_is_dropped(): void
    {
        $javascript = $this->service()->normalize([
            'branding' => ['logoUrl' => 'javascript:alert(1)'],
        ])['branding']['logoUrl'];

        $protocolRelative = $this->service()->normalize([
            'branding' => ['logoUrl' => '//evil.example/logo.png'],
        ])['branding']['logoUrl'];

        $relative = $this->service()->normalize([
            'branding' => ['logoUrl' => '/storage/logo.png'],
        ])['branding']['logoUrl'];

        $https = $this->service()->normalize([
            'branding' => ['logoUrl' => 'https://cdn.example/logo.png'],
        ])['branding']['logoUrl'];

        self::assertSame('', $javascript, 'javascript: не должен попасть в src');
        // Протокол-относительный адрес выглядит как путь, но ведёт на чужой
        // хост — это подмена логотипа, а не относительная ссылка.
        self::assertSame('', $protocolRelative);
        self::assertSame('/storage/logo.png', $relative);
        self::assertSame('https://cdn.example/logo.png', $https);
    }

    public function test_a_section_of_unknown_type_is_dropped(): void
    {
        $sections = $this->service()->normalize([
            'sections' => [
                ['type' => 'evil', 'title' => 'Чужой виджет'],
                ['type' => 'posters'],
            ],
        ])['sections'];

        self::assertCount(1, $sections);
        self::assertSame('posters', $sections[0]['type']);
    }

    public function test_a_section_id_with_markup_is_replaced(): void
    {
        // id уходит в DOM и в ключи Vue, поэтому допустимы только безопасные
        // символы. Недопустимый id не отбрасывает секцию — он заменяется
        // сгенерированным, иначе сохранение «съедало» бы блок целиком.
        $section = $this->service()->normalize([
            'sections' => [['id' => '<script>alert(1)</script>', 'type' => 'posters']],
        ])['sections'][0];

        self::assertSame('sec_posters_0', $section['id']);
    }

    public function test_section_settings_are_filtered_by_the_widget_whitelist(): void
    {
        $settings = $this->service()->normalize([
            'sections' => [[
                'type' => 'posters',
                'settings' => ['limit' => 5, 'evil' => 'x', 'layout' => 'list'],
            ]],
        ])['sections'][0]['settings'];

        self::assertSame(
            ['layout', 'columns', 'limit', 'sort', 'category', 'showFilters'],
            array_keys($settings),
            'набор полей виджета — контракт с фронтендом',
        );
        self::assertArrayNotHasKey('evil', $settings);
        self::assertSame(5, $settings['limit']);
        self::assertSame('list', $settings['layout']);
    }

    public function test_sections_are_capped_at_forty(): void
    {
        $input = array_fill(0, 45, ['type' => 'posters']);

        self::assertCount(40, $this->service()->normalize(['sections' => $input])['sections']);
    }

    // ── Админский путь ────────────────────────────────────────────────────

    public function test_storefront_management_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/storefront')->assertStatus(401);
        $this->putJson('/api/v1/admin/storefront', ['config' => []])->assertStatus(401);
        $this->postJson('/api/v1/admin/storefront/reset')->assertStatus(401);
    }

    public function test_a_plain_buyer_cannot_manage_the_storefront(): void
    {
        $buyer = $this->user('buyer@example.test');

        $this->getJson('/api/v1/admin/storefront', $this->headers($this->tokenFor($buyer)))
            ->assertStatus(403);
    }

    public function test_staff_saves_reads_and_resets_their_config(): void
    {
        $organizationId = $this->organization('Площадка');
        [, $token] = $this->staff($organizationId, 'admin@example.test');
        $headers = $this->headers($token);

        $saved = $this->putJson('/api/v1/admin/storefront', [
            'config' => ['theme' => ['preset' => 'emerald'], 'branding' => ['name' => 'Зал']],
        ], $headers)->assertOk()->json();

        self::assertSame('Зал', $saved['data']['branding']['name']);
        // Пресет задаёт кисти: выбранный emerald должен подставить свои цвета,
        // даже если администратор их не переопределял.
        self::assertSame(StorefrontService::PRESETS['emerald']['brand'], $saved['data']['theme']['brand']);
        self::assertFalse($saved['meta']['is_default']);

        $read = $this->getJson('/api/v1/admin/storefront', $headers)->assertOk()->json();
        self::assertSame('Зал', $read['data']['branding']['name']);
        self::assertFalse($read['meta']['is_default']);

        $reset = $this->postJson('/api/v1/admin/storefront/reset', [], $headers)->assertOk()->json();
        self::assertSame('NABILET', $reset['data']['branding']['name']);
        self::assertTrue($reset['meta']['is_default']);

        // После сброса в базе не остаётся строки организации: витрина снова
        // берёт дефолт, а не «последнее сохранённое».
        self::assertSame(0, DB::table('storefront_settings')->where('organization_id', $organizationId)->count());
    }

    public function test_a_config_that_is_not_an_object_is_rejected(): void
    {
        $organizationId = $this->organization('Площадка');
        [, $token] = $this->staff($organizationId, 'admin@example.test');

        $this->putJson('/api/v1/admin/storefront', ['config' => 'строка'], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_two_organizations_do_not_overwrite_each_other(): void
    {
        // Мультитенантность ТЗ §10: организация берётся из токена, а не из тела
        // запроса. Проверка «чужой id в теле игнорируется» — это и есть защита
        // от перезаписи витрины соседа.
        $first = $this->organization('Первая');
        $second = $this->organization('Вторая');

        [, $firstToken] = $this->staff($first, 'one@example.test');
        [, $secondToken] = $this->staff($second, 'two@example.test');

        $this->putJson('/api/v1/admin/storefront', [
            'config' => ['theme' => ['brand' => '#111111']],
            'organization_id' => $second,
        ], $this->headers($firstToken))->assertOk();

        $this->putJson('/api/v1/admin/storefront', [
            'config' => ['theme' => ['brand' => '#222222']],
        ], $this->headers($secondToken))->assertOk();

        self::assertSame('#111111', $this->service()->resolve((string) $first)['theme']['brand']);
        self::assertSame('#222222', $this->service()->resolve((string) $second)['theme']['brand']);
    }

    // ── Фикстуры ──────────────────────────────────────────────────────────

    private function organization(string $name): int
    {
        $now = now();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function user(string $email): User
    {
        $now = now();

        $id = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::query()->findOrFail($id);
    }

    /**
     * Сотрудник организации: роль для `admin`-middleware и членство для
     * `organizations()`.
     *
     * Это ДВЕ РАЗНЫЕ таблицы, и путать их нельзя: `StaffRole` читает
     * `user_roles` (`User::roles()`), а контроллер витрины определяет
     * организацию через `user_organization` (`User::organizations()`). Запись
     * только в `user_roles` даёт 403-проходимого сотрудника без организации —
     * и конфиг уехал бы в глобальную строку вместо строки площадки.
     *
     * @return array{0: User, 1: string}
     */
    private function staff(int $organizationId, string $email): array
    {
        $now = now();
        $user = $this->user($email);

        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));

        DB::table('user_roles')->insert([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        DB::table('user_organization')->insert([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        return [User::query()->findOrFail($user->id), $this->tokenFor($user)];
    }

    private function tokenFor(User $user): string
    {
        return $this->app->make(SessionIssuer::class)->issue($user->id);
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }
}
