<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;
use Nabilet\Modules\Notifications\Support\AccountNotificationCodes;
use Nabilet\Modules\Notifications\Support\OrderNotificationCodes;
use RuntimeException;

/**
 * Транзакционные письма по статусам заказа.
 *
 * Шаблон ищется по коду `order.<status>` — колонка `code` уже есть в схеме и
 * уникальна в паре с (channel, locale), поэтому отдельный `slug` не нужен.
 * Код шаблона совпадает со статусом заказа один в один: обсерверу не нужна
 * таблица соответствий, а администратор видит в списке понятные имена.
 *
 * Письма — РЕДАКТИРУЕМЫЕ: сидер только создаёт стартовое содержимое и
 * идемпотентно создаёт недостающие шаблоны. Уже существующие записи
 * оставляются без изменений, поэтому повторный сид не перезаписывает тексты,
 * которые администратор правил в админке. Это важно для сценария деплоя: сид
 * можно безопасно запускать повторно, не теряя кастомный текст магазина.
 *
 * Подстановка: `{{переменная}}`. Список доступных переменных — в
 * TransactionalMailService::availableVariables(); неизвестные переменные
 * сервис оставляет как есть, чтобы опечатка была видна в письме, а не
 * приводила к пустому месту.
 */
final class NotificationTemplateSeeder extends Seeder
{
    public const CHANNEL = 'email';
    public const LOCALE = 'ru';

    /**
     * Коды шаблонов берутся из OrderNotificationCodes — того же списка, по
     * которому обсервер заказа ищет шаблон. Дублировать соответствие здесь
     * нельзя: шаблон появится в БД, а письмо по нему не уйдёт.
     */
    public function run(): void
    {
        $templates = array_merge($this->orderTemplates(), $this->accountTemplates());

        // Сид и обсервер обязаны знать один и тот же набор кодов ЗАКАЗА. Если
        // здесь появится шаблон заказа, которого нет в OrderNotificationCodes
        // (или наоборот), сид падает — расхождение должно быть видно сразу, а не
        // по отсутствию писем на проде.
        //
        // Сверяются только коды `order.*`: письма о доступе к аккаунту не
        // приходят от обсервера заказа, у них свой список
        // (`AccountNotificationCodes`), и добавлять их в проверку значило бы
        // сравнивать два разных набора.
        $seededOrders = array_values(array_filter(
            array_column($templates, 'code'),
            static fn (string $code): bool => str_starts_with($code, 'order.'),
        ));
        $expected = OrderNotificationCodes::all();
        sort($seededOrders);
        sort($expected);

        if ($seededOrders !== $expected) {
            throw new RuntimeException(sprintf(
                'Расхождение кодов шаблонов заказов: в сиде [%s], в OrderNotificationCodes [%s].',
                implode(', ', $seededOrders),
                implode(', ', $expected),
            ));
        }

        foreach ($templates as $template) {
            $existing = NotificationTemplate::query()
                ->where('code', $template['code'])
                ->where('channel', self::CHANNEL)
                ->where('locale', self::LOCALE)
                ->first();

            if ($existing === null) {
                NotificationTemplate::query()->create($template + [
                    'channel' => self::CHANNEL,
                    'locale' => self::LOCALE,
                ]);

                continue;
            }

            // Уже существует: не трогаем. Правки администратора переживают
            // повторный сид — это единственный способ совместить «шаблоны
            // редактируются» и «шаблоны поставляются из кода».
            $this->command?->getOutput()->writeln(
                sprintf('  Шаблон %s уже существует — оставлен без изменений.', $template['code'])
            );
        }
    }

    /**
     * @return list<array{code: string, subject: string, body_html: string, body_text: string}>
     */
    private function orderTemplates(): array
    {
        return [
            $this->template(
                code: 'order.awaiting_payment',
                subject: 'Заказ {{order_number}} ожидает оплаты',
                intro: 'Здравствуйте, {{customer_name}}!',
                body: 'Ваш заказ <b>№{{order_number}}</b> создан и ожидает оплаты.'
                    . ' Места забронированы за вами на ограниченное время — если оплата не'
                    . ' поступит, бронь будет снята и места вернутся в продажу.',
                action: 'Чтобы завершить покупку, вернитесь к оплате заказа.',
                text: "Здравствуйте, {{customer_name}}!\n\n"
                    . "Ваш заказ №{{order_number}} создан и ожидает оплаты.\n"
                    . "Места забронированы за вами на ограниченное время — если оплата не"
                    . " поступит, бронь будет снята и места вернутся в продажу.\n\n"
                    . "Мероприятие: {{event_name}}\n"
                    . "Дата: {{event_date}}\n"
                    . "Место: {{venue_name}}\n"
                    . "Сумма: {{total}}\n",
            ),
            $this->template(
                code: 'order.paid',
                subject: 'Ваши билеты на {{event_name}}',
                intro: 'Здравствуйте, {{customer_name}}!',
                body: 'Спасибо за покупку. Заказ <b>№{{order_number}}</b> оплачен,'
                    . ' билеты выпущены и находятся в этом письме.',
                action: 'Покажите QR-код с билета на входе — скачивать и распечатывать'
                    . ' его не обязательно.',
                text: "Здравствуйте, {{customer_name}}!\n\n"
                    . "Спасибо за покупку. Заказ №{{order_number}} оплачен, билеты выпущены.\n\n"
                    . "Мероприятие: {{event_name}}\n"
                    . "Дата: {{event_date}}\n"
                    . "Место: {{venue_name}}\n"
                    . "Сумма: {{total}}\n\n"
                    . "{{tickets_text}}\n",
                withTickets: true,
            ),
            $this->template(
                code: 'order.cancelled',
                subject: 'Заказ {{order_number}} отменён',
                intro: 'Здравствуйте, {{customer_name}}!',
                body: 'Заказ <b>№{{order_number}}</b> отменён. Забронированные места'
                    . ' вернулись в продажу.',
                action: 'Если отмена произошла по ошибке, вы можете оформить заказ заново.',
                text: "Здравствуйте, {{customer_name}}!\n\n"
                    . "Заказ №{{order_number}} отменён. Забронированные места вернулись в продажу.\n\n"
                    . "Мероприятие: {{event_name}}\n"
                    . "Сумма: {{total}}\n",
            ),
            $this->template(
                code: 'order.refunded',
                subject: 'Возврат по заказу {{order_number}}',
                intro: 'Здравствуйте, {{customer_name}}!',
                body: 'По заказу <b>№{{order_number}}</b> выполнен возврат средств на'
                    . ' сумму {{total}}. Билеты аннулированы.',
                action: 'Срок зачисления зависит от банка, обычно это до 10 рабочих дней.',
                text: "Здравствуйте, {{customer_name}}!\n\n"
                    . "По заказу №{{order_number}} выполнен возврат средств на сумму {{total}}."
                    . " Билеты аннулированы.\n\n"
                    . "Мероприятие: {{event_name}}\n",
            ),
            $this->template(
                code: 'order.partially_refunded',
                subject: 'Частичный возврат по заказу {{order_number}}',
                intro: 'Здравствуйте, {{customer_name}}!',
                body: 'По заказу <b>№{{order_number}}</b> выполнен частичный возврат.'
                    . ' Возвращённые билеты аннулированы, остальные остаются действительными.',
                action: 'Проверьте, какие билеты остались в силе, перед посещением мероприятия.',
                text: "Здравствуйте, {{customer_name}}!\n\n"
                    . "По заказу №{{order_number}} выполнен частичный возврат."
                    . " Возвращённые билеты аннулированы, остальные остаются действительными.\n\n"
                    . "Мероприятие: {{event_name}}\n",
            ),
            $this->template(
                code: 'order.payment_failed',
                subject: 'Не удалось оплатить заказ {{order_number}}',
                intro: 'Здравствуйте, {{customer_name}}!',
                body: 'Оплата заказа <b>№{{order_number}}</b> не прошла. Бронь пока'
                    . ' сохраняется — можно повторить попытку другой картой.',
                action: 'Вернитесь к заказу и попробуйте оплатить снова.',
                text: "Здравствуйте, {{customer_name}}!\n\n"
                    . "Оплата заказа №{{order_number}} не прошла. Бронь пока сохраняется —"
                    . " можно повторить попытку другой картой.\n\n"
                    . "Мероприятие: {{event_name}}\n"
                    . "Сумма: {{total}}\n",
            ),
            $this->template(
                code: 'order.expired',
                subject: 'Срок оплаты заказа {{order_number}} истёк',
                intro: 'Здравствуйте, {{customer_name}}!',
                body: 'Срок оплаты заказа <b>№{{order_number}}</b> истёк, бронь снята.'
                    . ' Места вернулись в продажу.',
                action: 'Если мероприятие ещё актуально, оформите заказ заново.',
                text: "Здравствуйте, {{customer_name}}!\n\n"
                    . "Срок оплаты заказа №{{order_number}} истёк, бронь снята."
                    . " Места вернулись в продажу.\n\n"
                    . "Мероприятие: {{event_name}}\n",
            ),
            // Напоминание за сутки до мероприятия. Единственное письмо, которое
            // приходит не по смене статуса, а по времени: его рассылает свип
            // (Orders\Services\OrderReminderSweeper), а не обсервер заказа.
            //
            // Билеты прикладываются ПОЛНОСТЬЮ (`withTickets`): смысл письма —
            // чтобы билет был под рукой в день концерта, у человека, который
            // купил его месяц назад и уже потерял исходное письмо. Ради этого
            // напоминание и существует; письмо без QR-кода не решало бы задачу.
            $this->template(
                code: OrderNotificationCodes::REMINDER,
                subject: 'Напоминаем: {{event_name}} — завтра',
                intro: 'Здравствуйте, {{customer_name}}!',
                body: 'Напоминаем, что <b>{{event_name}}</b> состоится уже завтра.'
                    . ' Ваш заказ <b>№{{order_number}}</b> оплачен — билеты ниже,'
                    . ' это письмо можно показать на входе.',
                action: 'Проверьте дату и время заранее и приходите с QR-кодом.'
                    . ' Скачивать и распечатывать билет не обязательно.',
                text: "Здравствуйте, {{customer_name}}!\n\n"
                    . "Напоминаем, что {{event_name}} состоится уже завтра."
                    . " Ваш заказ №{{order_number}} оплачен.\n\n"
                    . "Мероприятие: {{event_name}}\n"
                    . "Дата: {{event_date}}\n"
                    . "Место: {{venue_name}}\n\n"
                    . "{{tickets_text}}\n",
                withTickets: true,
            ),
        ];

    }

    /**
     * Письма о доступе к аккаунту: восстановление пароля и подтверждение адреса.
     *
     * Переменных здесь меньше, чем у заказов: `{{action_url}}` — готовая ссылка,
     * собранная сервисом (токен в шаблон не попадает), и `{{expires_in_minutes}}`
     * для текста о сроке. Список доступных переменных объявлен в
     * `AccountNotificationCodes::variables()` и совпадает с тем, что
     * подставляет `AccountMailService`.
     *
     * Тон писем — намеренно без деталей о событии и сумме: это письмо о
     * безопасности, и единственное, что читателю нужно, — что делать дальше.
     * Если действие запрошено не им, он должен понять это из первой строки.
     *
     * @return list<array{code: string, subject: string, body_html: string, body_text: string}>
     */
    private function accountTemplates(): array
    {
        return [
            $this->accountTemplate(
                code: AccountNotificationCodes::PASSWORD_RESET,
                subject: 'Смена пароля',
                intro: 'Здравствуйте, {{customer_name}}!',
                body: 'Вы запросили смену пароля. Нажмите кнопку ниже, чтобы задать новый пароль.',
                action: 'Если вы не запрашивали смену пароля, просто проигнорируйте это письмо —'
                    . ' пароль останется прежним.',
                urlLabel: 'Задать новый пароль',
                text: "Здравствуйте, {{customer_name}}!\n\n"
                    . "Вы запросили смену пароля. Перейдите по ссылке, чтобы задать новый:\n"
                    . "{{action_url}}\n\n"
                    . "Ссылка действует {{expires_in_minutes}} минут.\n"
                    . "Если вы не запрашивали смену пароля, проигнорируйте это письмо.\n",
            ),
            $this->accountTemplate(
                code: AccountNotificationCodes::EMAIL_VERIFICATION,
                subject: 'Подтверждение адреса электронной почты',
                intro: 'Здравствуйте, {{customer_name}}!',
                body: 'Подтвердите адрес электронной почты, чтобы получать билеты и уведомления о заказах.',
                action: 'Если вы не регистрировались на нашем сайте, просто проигнорируйте это письмо.',
                urlLabel: 'Подтвердить адрес',
                text: "Здравствуйте, {{customer_name}}!\n\n"
                    . "Подтвердите адрес электронной почты:\n"
                    . "{{action_url}}\n\n"
                    . "Ссылка действует {{expires_in_minutes}} минут.\n"
                    . "Если вы не регистрировались, проигнорируйте это письмо.\n",
            ),
        ];
    }

    /**
     * Собрать запись шаблона письма о доступе к аккаунту.
     *
     * @return array{code: string, subject: string, body_html: string, body_text: string}
     */
    private function accountTemplate(
        string $code,
        string $subject,
        string $intro,
        string $body,
        string $action,
        string $urlLabel,
        string $text,
    ): array {
        // Кнопка-ссылка собирается платформенно-нейтрально (таблица вместо
        // flex/grid): почтовые клиенты Outlook и старые мобильные клиенты
        // выкидывают современную вёрстку, и нажимаемая кнопка превращается в
        // строку без ссылки — а это единственное, ради чего письмо отправлено.
        $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,'
            . 'sans-serif;max-width:600px;margin:0 auto;color:#111827;">'
            . '<p style="margin:0 0 12px;">' . $intro . '</p>'
            . '<p style="margin:0 0 20px;line-height:1.5;">' . $body . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="border-radius:6px;background:#4f46e5;">'
            . '<a href="{{action_url}}" target="_blank" rel="noopener"'
            . ' style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:600;'
            . 'color:#ffffff;text-decoration:none;">' . $urlLabel . '</a></td></tr></table>'
            . '<p style="margin:20px 0 0;font-size:13px;line-height:1.5;color:#6b7280;">'
            . 'Ссылка действует {{expires_in_minutes}} минут.</p>'
            . '<p style="margin:12px 0 0;line-height:1.5;color:#4b5563;">' . $action . '</p>'
            . '</div>';

        return [
            'code' => $code,
            'subject' => $subject,
            'body_html' => $html,
            'body_text' => $text,
        ];
    }

    /**
     * Собрать запись шаблона: HTML и plain-text версии одного и того же письма.
     *
     * @return array{code: string, subject: string, body_html: string, body_text: string}
     */
    private function template(
        string $code,
        string $subject,
        string $intro,
        string $body,
        string $action,
        string $text,
        bool $withTickets = false,
    ): array {
        $details = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
            . ' style="width:100%;margin:16px 0;font-size:14px;color:#374151;">'
            . '<tr><td style="padding:4px 0;color:#6b7280;">Мероприятие</td>'
            . '<td style="padding:4px 0;text-align:right;font-weight:600;">{{event_name}}</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280;">Дата</td>'
            . '<td style="padding:4px 0;text-align:right;">{{event_date}}</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280;">Место проведения</td>'
            . '<td style="padding:4px 0;text-align:right;">{{venue_name}}</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280;">Сумма</td>'
            . '<td style="padding:4px 0;text-align:right;font-weight:600;">{{total}}</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280;">Заказ</td>'
            . '<td style="padding:4px 0;text-align:right;">№{{order_number}}</td></tr>'
            . '</table>';

        // Билеты целиком готовятся сервисом: QR-подпись и вёрстка одного билета
        // не должны жить в шаблоне, иначе администратор может сломать выдачу.
        $tickets = $withTickets
            ? '<div style="margin:20px 0;">{{tickets_html}}</div>'
            : '';

        $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,'
            . 'sans-serif;max-width:600px;margin:0 auto;color:#111827;">'
            . '<p style="margin:0 0 12px;">' . $intro . '</p>'
            . '<p style="margin:0 0 16px;line-height:1.5;">' . $body . '</p>'
            . $details
            . $tickets
            . '<p style="margin:16px 0 0;line-height:1.5;color:#4b5563;">' . $action . '</p>'
            . '</div>';

        return [
            'code' => $code,
            'subject' => $subject,
            'body_html' => $html,
            'body_text' => $text,
        ];
    }
}
