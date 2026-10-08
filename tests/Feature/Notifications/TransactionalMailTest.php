<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Nabilet\Modules\Notifications\Mail\TemplateMail;
use Nabilet\Modules\Notifications\Services\TransactionalMailService;
use Nabilet\Modules\Notifications\Support\AccountNotificationCodes;
use Nabilet\Modules\Notifications\Support\OrderNotificationCodes;
use Tests\TestCase;

class TransactionalMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_editable_template_and_logs_success_without_persisting_pii_payload(): void
    {
        Mail::fake();

        $template = NotificationTemplate::query()->create([
            'code' => 'order.paid',
            'channel' => 'email',
            'locale' => 'ru',
            'subject' => 'Заказ {{order_number}} оплачен',
            'body_html' => '<p>{{customer_name}}</p><div>{{tickets_html}}</div>',
            'body_text' => 'Здравствуйте, {{customer_name}} — {{order_number}}',
            'active' => true,
        ]);

        $sent = app(TransactionalMailService::class)->send(
            code: 'order.paid',
            recipient: 'buyer@example.test',
            variables: [
                'customer_name' => '<script>alert(1)</script>',
                'order_number' => 'NB-20261007-0001',
                'tickets_html' => new \Illuminate\Support\HtmlString('<b>QR: signed-payload</b>'),
            ],
        );

        self::assertTrue($sent);

        Mail::assertSent(TemplateMail::class, function (TemplateMail $mail): bool {
            return $mail->subjectLine === 'Заказ NB-20261007-0001 оплачен'
                && str_contains($mail->htmlBody, '&lt;script&gt;alert(1)&lt;/script&gt;')
                && str_contains($mail->htmlBody, '<b>QR: signed-payload</b>')
                && $mail->textBody === 'Здравствуйте, <script>alert(1)</script> — NB-20261007-0001';
        });

        $notification = Notification::query()->where('type', 'order.paid')->firstOrFail();
        self::assertSame('sent', $notification->status);
        self::assertSame('buyer@example.test', $notification->recipient);
        self::assertSame(['variables' => ['customer_name', 'order_number', 'tickets_html']], $notification->payload_json);
        self::assertArrayNotHasKey('customer_name', $notification->payload_json);
        self::assertSame($template->id, NotificationTemplate::query()->firstOrFail()->id);
    }

    public function test_disabled_template_is_not_sent(): void
    {
        Mail::fake();

        NotificationTemplate::query()->create([
            'code' => 'order.cancelled',
            'channel' => 'email',
            'locale' => 'ru',
            'subject' => 'Заказ отменён',
            'body_html' => '<p>Отменён</p>',
            'body_text' => 'Отменён',
            'active' => false,
        ]);

        self::assertFalse(app(TransactionalMailService::class)->send(
            'order.cancelled',
            'buyer@example.test',
            [],
        ));

        Mail::assertNothingOutgoing();
        self::assertSame(0, Notification::query()->count());
    }

    public function test_default_status_templates_are_idempotently_seeded_without_overwriting_edits(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        // Считаем не константой, а по списку кодов: сид обязан поставить ровно
        // один шаблон на каждый код заказа плюс письма о доступе к аккаунту.
        // Число, вбитое руками, приходилось бы править при каждом новом письме
        // (так и случилось при добавлении `order.reminder`), и тест падал бы не
        // на дефекте, а на расхождении счётчика.
        $expected = count(OrderNotificationCodes::all())
            + count(AccountNotificationCodes::all());

        self::assertSame($expected, NotificationTemplate::query()->where('channel', 'email')->count());

        $template = NotificationTemplate::query()->where('code', 'order.paid')->firstOrFail();
        $template->update(['subject' => 'Мой изменённый заголовок']);

        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        self::assertSame('Мой изменённый заголовок', $template->fresh()->subject);
        self::assertSame($expected, NotificationTemplate::query()->where('channel', 'email')->count());
    }
}
