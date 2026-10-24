<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Nabilet\Modules\Notifications\Mail\TemplateMail;
use Nabilet\Modules\Notifications\Models\NotificationTemplate;
use Nabilet\Modules\Notifications\Services\AccountMailService;
use Nabilet\Modules\Notifications\Services\NotificationTemplateRenderer;
use Nabilet\Modules\Notifications\Services\TransactionalMailService;
use Nabilet\Modules\Notifications\Support\AccountNotificationCodes;
use Tests\TestCase;

/**
 * Правило «шаблон → готовое письмо» у писем о заказе и о доступе к аккаунту
 * одно.
 *
 * Раньше `renderHtml`/`renderText` были скопированы в оба сервиса, и копия в
 * `AccountMailService` отстала: в ней не было ветки `Htmlable` и защиты от
 * нескалярных значений. Живого дефекта не было только потому, что список
 * переменных там — три скаляра; первый же готовый HTML в письме о пароле уехал
 * бы покупателю экранированным, то есть тегами вместо разметки.
 *
 * Поэтому тесты ниже проверяют не только владельца, но и то, что ОБА сервиса
 * дают одинаковый результат: расхождение копий и было дефектом.
 */
class NotificationTemplateRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_only_active_template_of_the_email_channel_and_russian_locale(): void
    {
        $renderer = app(NotificationTemplateRenderer::class);

        $template = $this->template(['code' => 'account.password_reset']);

        self::assertSame($template->id, $renderer->find('account.password_reset')?->id);
        self::assertNull($renderer->find('account.unknown'), 'чужого кода быть не может');

        // Выключенный шаблон — это «письма нет», а не «взять встроенный текст»:
        // иначе переключатель в админке врал бы.
        $template->update(['active' => false]);
        self::assertNull($renderer->find('account.password_reset'));

        $template->update(['active' => true, 'locale' => 'en']);
        self::assertNull($renderer->find('account.password_reset'), 'другая локаль');

        $template->update(['locale' => 'ru', 'channel' => 'sms']);
        self::assertNull($renderer->find('account.password_reset'), 'другой канал');
    }

    public function test_html_is_escaped_and_text_is_not(): void
    {
        $rendered = app(NotificationTemplateRenderer::class)->render(
            $this->template([
                'subject' => 'Заказ {{order_number}}',
                'body_html' => '<p>{{customer_name}}</p>',
                'body_text' => 'Здравствуйте, {{customer_name}}',
            ]),
            ['customer_name' => '<script>alert(1)</script>', 'order_number' => 'NB-1'],
        );

        self::assertSame('Заказ NB-1', $rendered['subject']);
        self::assertSame(
            '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
            $rendered['html'],
            'имя покупателя вводит он сам — в HTML только экранированным',
        );
        self::assertSame(
            'Здравствуйте, <script>alert(1)</script>',
            $rendered['text'],
            'в текстовой версии &amp; читался бы как мусор',
        );
    }

    public function test_htmlable_is_inserted_raw_into_html_and_stripped_in_text(): void
    {
        $rendered = app(NotificationTemplateRenderer::class)->render(
            $this->template([
                'subject' => 'Билеты',
                'body_html' => '<div>{{tickets_html}}</div>',
                'body_text' => 'Билеты: {{tickets_html}}',
            ]),
            ['tickets_html' => new HtmlString('<b>QR: signed-payload</b>')],
        );

        self::assertSame('<div><b>QR: signed-payload</b></div>', $rendered['html']);
        self::assertSame('Билеты: QR: signed-payload', $rendered['text']);
    }

    public function test_null_bool_and_non_scalar_variables_render_predictably(): void
    {
        $rendered = app(NotificationTemplateRenderer::class)->render(
            $this->template([
                'subject' => '',
                'body_html' => '[{{a}}][{{b}}][{{c}}][{{d}}]',
                'body_text' => '[{{a}}][{{b}}][{{c}}][{{d}}]',
            ]),
            ['a' => null, 'b' => true, 'c' => false, 'd' => ['x']],
        );

        // Массив в теле письма — всегда ошибка сборки данных; пустое место
        // заметнее, чем «Array» в письме покупателю.
        self::assertSame('[][1][][]', $rendered['html']);
        self::assertSame('[][1][][]', $rendered['text']);
    }

    public function test_empty_body_renders_empty_string(): void
    {
        $rendered = app(NotificationTemplateRenderer::class)->render(
            $this->template(['subject' => null, 'body_html' => null, 'body_text' => null]),
            ['customer_name' => 'Иван'],
        );

        self::assertSame(['subject' => '', 'html' => '', 'text' => ''], $rendered);
    }

    /**
     * Главная проверка: письмо о доступе к аккаунту и письмо о заказе рендерят
     * одинаково. Пока правило было скопировано, они расходились на `Htmlable`.
     */
    public function test_both_mail_services_produce_the_same_body(): void
    {
        Mail::fake();

        $variables = [
            'customer_name' => '<b>Иван</b>',
            'tickets_html' => new HtmlString('<i>билет</i>'),
        ];

        $this->template([
            'code' => 'order.paid',
            'subject' => '{{customer_name}}',
            'body_html' => '{{customer_name}} {{tickets_html}}',
            'body_text' => '{{customer_name}} {{tickets_html}}',
        ]);

        $order = app(TransactionalMailService::class)->renderTemplate(
            NotificationTemplate::query()->where('code', 'order.paid')->firstOrFail(),
            $variables,
        );

        // Письмо об аккаунте берёт шаблон по своему коду, поэтому проверяем
        // через отправку: результат виден в собранном `TemplateMail`.
        $this->template([
            'code' => AccountNotificationCodes::PASSWORD_RESET,
            'subject' => '{{customer_name}}',
            'body_html' => '{{customer_name}} {{tickets_html}}',
            'body_text' => '{{customer_name}} {{tickets_html}}',
        ]);

        self::assertTrue(app(AccountMailService::class)->send(
            AccountNotificationCodes::PASSWORD_RESET,
            'buyer@example.test',
            $variables,
        ));

        Mail::assertSent(TemplateMail::class, function (TemplateMail $mail) use ($order): bool {
            return $mail->subjectLine === $order['subject']
                && $mail->htmlBody === $order['html']
                && $mail->textBody === $order['text'];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function template(array $attributes): NotificationTemplate
    {
        return NotificationTemplate::query()->create($attributes + [
            'code' => 'account.password_reset',
            'channel' => NotificationTemplateRenderer::CHANNEL,
            'locale' => NotificationTemplateRenderer::LOCALE,
            'subject' => 'Тема',
            'body_html' => '<p>Тело</p>',
            'body_text' => 'Тело',
            'active' => true,
        ]);
    }
}
