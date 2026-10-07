<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;
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
        $templates = $this->templates();

        // Сид и обсервер обязаны знать один и тот же набор кодов. Если здесь
        // появится шаблон, которого нет в OrderNotificationCodes (или наоборот),
        // сид падает — расхождение должно быть видно сразу, а не по отсутствию
        // писем на проде.
        $seeded = array_column($templates, 'code');
        $expected = OrderNotificationCodes::all();
        sort($seeded);
        sort($expected);

        if ($seeded !== $expected) {
            throw new RuntimeException(sprintf(
                'Расхождение кодов шаблонов: в сиде [%s], в OrderNotificationCodes [%s].',
                implode(', ', $seeded),
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
    private function templates(): array
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
