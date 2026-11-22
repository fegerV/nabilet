<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Notifications\Services\TransactionalMailService;
use Tests\TestCase;

/**
 * Админский редактор шаблонов писем: контракт API и полнота переменных.
 *
 * До этого файла эндпоинты index/show/update/preview вообще не были покрыты,
 * а список переменных и демо-данные разошлись с письмами о розыгрыше/пароле.
 */
final class NotificationTemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_exposes_every_variable_used_by_any_mail(): void
    {
        $this->actingAsAdmin();
        $templateId = $this->seedTemplate();

        $data = $this->getJson('/api/v1/notification-templates')
            ->assertOk()
            ->json('data');

        $variables = $data['variables'];
        foreach (['action_url', 'expires_in_minutes', 'sales_note', 'raffle_title', 'prize_place', 'prize_title'] as $name) {
            self::assertContains($name, $variables, "переменная {$name} отсутствует в редакторе");
        }
        foreach (TransactionalMailService::availableVariables() as $name) {
            self::assertContains($name, $variables);
        }
        self::assertNotSame([], $data['templates']);
    }

    public function test_update_keeps_the_template_identity_and_persists_the_draft(): void
    {
        $this->actingAsAdmin();
        $id = $this->seedTemplate();

        $this->putJson("/api/v1/notification-templates/{$id}", [
            'subject' => 'Новая тема',
            'body_html' => '<p>Привет, {{customer_name}}</p>',
            'code' => 'hacked.code',
            'channel' => 'sms',
            'locale' => 'en',
        ])->assertOk();

        $row = DB::table('notification_templates')->where('id', $id)->firstOrFail();
        self::assertSame('Новая тема', $row->subject);
        self::assertSame('order.paid', $row->code, 'идентичность шаблона нельзя менять правкой');
        self::assertSame('email', $row->channel);
        self::assertSame('ru', $row->locale);
    }

    public function test_preview_uses_unsaved_fields_but_ignores_client_tickets_html(): void
    {
        $this->actingAsAdmin();
        $id = $this->seedTemplate();

        $result = $this->postJson("/api/v1/notification-templates/{$id}/preview", [
            'subject' => 'Черновик: {{order_number}}',
            'body_html' => '<p>{{customer_name}} — {{tickets_html}}</p>',
            'body_text' => '{{customer_name}} / {{tickets_text}}',
            'tickets_html' => '<script>alert(1)</script>',
        ])->assertOk()->json('data');

        self::assertSame('Черновик: NB-20261007-DEMO0001', $result['subject']);
        self::assertStringContainsString('Иван Петров', $result['html']);
        self::assertStringNotContainsString('alert(1)', $result['html']);
        self::assertStringContainsString('Билет', $result['text']);
    }

    public function test_preview_substitutes_every_available_variable(): void
    {
        $this->actingAsAdmin();
        $id = $this->seedTemplate();
        $placeholders = '';
        foreach (TransactionalMailService::availableVariables() as $name) {
            $placeholders .= '{{' . $name . '}} ';
        }

        $result = $this->postJson("/api/v1/notification-templates/{$id}/preview", [
            'body_html' => $placeholders,
            'body_text' => $placeholders,
        ])->assertOk()->json('data');

        self::assertStringNotContainsString('{{', $result['html'], 'демо-данные должны покрывать все переменные');
        self::assertStringNotContainsString('{{', $result['text']);
    }

    private function actingAsAdmin(): void
    {
        $now = now();
        $orgId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'surgut-koncert',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'mail-admin@example.com',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId, 'organization_id' => $orgId, 'role_id' => $roleId, 'created_at' => $now,
        ]);
        DB::table('user_organization')->insert([
            'user_id' => $userId, 'organization_id' => $orgId, 'role_id' => $roleId, 'created_at' => $now,
        ]);

        $this->actingAs(\Nabilet\Modules\Core\Users\Models\User::query()->findOrFail($userId), 'api');
    }

    private function seedTemplate(): int
    {
        return (int) DB::table('notification_templates')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'code' => 'order.paid',
            'channel' => 'email',
            'locale' => 'ru',
            'subject' => 'Заказ оплачен',
            'body_html' => '<p>Спасибо!</p>',
            'body_text' => 'Спасибо!',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
