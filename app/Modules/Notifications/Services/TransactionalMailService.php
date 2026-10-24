<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Services;

use Nabilet\Modules\Notifications\Domain\NotificationStatus;
use Nabilet\Modules\Notifications\Models\Notification;
use Nabilet\Modules\Notifications\Models\NotificationTemplate;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Nabilet\Modules\Notifications\Mail\TemplateMail;
use Throwable;

/**
 * Транзакционные письма по редактируемым шаблонам.
 *
 * Шаблон лежит в БД (`notification_templates`) и ищется по коду, поэтому текст
 * письма правится администратором без деплоя. Код шаблона = `order.<status>`,
 * что избавляет от таблицы соответствий статус→шаблон.
 *
 * ПОИСК ШАБЛОНА И ПОДСТАНОВКА ПЕРЕМЕННЫХ ЖИВУТ НЕ ЗДЕСЬ, а в
 * `NotificationTemplateRenderer`: то же правило нужно письмам о доступе к
 * аккаунту (`AccountMailService`), и две его копии уже разошлись. Класс
 * занимается только транспортом — журналом `notifications`, скрытой копией и
 * обработкой сбоя SMTP.
 *
 * Сбой транспорта пишется в лог и в запись `notifications` со статусом
 * `failed`, после чего исключение пробрасывается обратно в очередь. Письмо
 * вызывается из джоб, которые уже ушли `afterCommit()` (по статусу заказа, по
 * подписке на мероприятие); поэтому транспортный сбой не отменяет оплаченный
 * заказ и не трогает подписку, зато Laravel сможет повторить его по настройке
 * `tries`/`backoff`.
 */
class TransactionalMailService
{
    /**
     * Канал и локаль — не свои константы, а ссылка на владельца.
     *
     * Литералы `'email'`/`'ru'` стояли здесь и в `AccountMailService` по разу,
     * плюс участвовали в запросе админки; два независимых литерала — это две
     * точки правки при появлении второй локали. Владелец — рендерер шаблонов,
     * потому что именно он по ним ищет шаблон.
     */
    public const CHANNEL = NotificationTemplateRenderer::CHANNEL;
    public const LOCALE = NotificationTemplateRenderer::LOCALE;

    public function __construct(
        private readonly NotificationTemplateRenderer $templates,
    ) {}

    /**
     * Переменные, доступные в шаблоне. Список показывается администратору в
     * редакторе шаблонов, чтобы не угадывать имена.
     *
     * Список — ОБЪЕДИНЕНИЕ по всем видам писем (заказы, доступ к аккаунту,
     * подписки на мероприятия): редактор шаблонов один на все коды, и
     * плейсхолдер из чужого письма должен быть в нём виден — иначе при
     * предпросмотре он молча не подставится. Что подставляет конкретный код:
     * заказы — `OrderNotificationData::build()`, доступ к аккаунту —
     * `AccountNotificationCodes::variables()`, подписки —
     * `FollowNotificationCodes::variables()`.
     *
     * @return list<string>
     */
    public static function availableVariables(): array
    {
        return [
            'customer_name',
            'order_number',
            'event_name',
            'event_date',
            'venue_name',
            'total',
            'tickets_html',
            'tickets_text',
            'action_url',
            'expires_in_minutes',
            'sales_note',
        ];
    }

    /**
     * Отправить письмо по редактируемому шаблону.
     *
     * @param  string  $code  код шаблона, например `order.paid`
     * @param  array<string, scalar|Htmlable|null>  $variables
     * @param  string|null  $bcc  скрытая копия (площадка / владелец магазина).
     *   Отдельным полем письма, а не второй отправкой: одно SMTP-соединение, один
     *   Message-ID и гарантия, что копия видит ровно то же письмо, что покупатель.
     *   Пустая строка и невалидный адрес игнорируются — дефект настройки не
     *   должен ронять письмо покупателю.
     * @return Notification|null запись из `notifications` — «письмо ушло в
     *   провайдера». `null` — отправки не было: некуда (пустой адрес) или
     *   шаблон выключен. Вызывающему это различие нужно, чтобы связать свой
     *   журнал (`follow_notifications.notification_id`) с конкретной записью
     *   транспорта, а не искать её заново после отправки.
     */
    public function send(string $code, string $recipient, array $variables, ?int $userId = null, ?string $bcc = null): ?Notification
    {
        if (trim($recipient) === '') {
            // Некуда отправлять — не считаем это ошибкой письма: у заказа может
            // не быть e-mail (оформление без контакта), и это отдельная проблема.
            return null;
        }

        $template = $this->findTemplate($code);

        if ($template === null) {
            Log::warning('Шаблон письма не найден или выключен — письмо не отправлено.', [
                'code' => $code,
            ]);

            return null;
        }

        $body = $this->render($template, $variables);

        $log = Notification::query()->create([
            'user_id' => $userId,
            'channel' => self::CHANNEL,
            'type' => $code,
            'recipient' => $recipient,
            'status' => NotificationStatus::QUEUED,
            'payload_json' => $this->loggableVariables($variables),
        ]);

        try {
            $pending = Mail::to($recipient);

            if ($this->isUsableAddress($bcc)) {
                $pending->bcc(trim((string) $bcc));
            }

            $pending->send(new TemplateMail(
                subjectLine: $body['subject'],
                htmlBody: $body['html'],
                textBody: $body['text'],
            ));

            $log->update([
                'status' => NotificationStatus::SENT,
                'sent_at' => now(),
            ]);

            return $log;
        } catch (Throwable $e) {
            // SMTP-клиент иногда включает адрес получателя в сообщение ошибки.
            // Убираем его и из логов, и из Notification.error_message: адрес уже
            // хранится в recipient-колонке, дублировать PII в свободном тексте не надо.
            $safeMessage = str_ireplace($recipient, '[адрес скрыт]', $e->getMessage());

            $log->update([
                'status' => NotificationStatus::FAILED,
                'error_message' => $safeMessage,
            ]);

            Log::error('Не удалось отправить транзакционное письмо.', [
                'code' => $code,
                'notification_id' => $log->id,
                'exception' => $e::class,
                'message' => $safeMessage,
            ]);

            throw $e;
        }
    }

    /**
     * Готовый текст письма без отправки — для предпросмотра в админке.
     *
     * @param  array<string, scalar|Htmlable|null>  $variables
     * @return array{subject: string, html: string, text: string}|null
     */
    public function render(string|NotificationTemplate $codeOrTemplate, array $variables = []): ?array
    {
        $template = $codeOrTemplate instanceof NotificationTemplate
            ? $codeOrTemplate
            : $this->findTemplate($codeOrTemplate);

        return $template === null ? null : $this->templates->render($template, $variables);
    }

    public function findTemplate(string $code): ?NotificationTemplate
    {
        return $this->templates->find($code);
    }

    /**
     * @param  array<string, scalar|Htmlable|null>  $variables
     * @return array{subject: string, html: string, text: string}
     */
    public function renderTemplate(NotificationTemplate $template, array $variables): array
    {
        return $this->templates->render($template, $variables);
    }

    /**
     * Адрес скрытой копии пригоден к использованию.
     *
     * Проверка здесь, а не у вызывающего, намеренно: настроечный адрес правит
     * человек в форме, и «забыл @» не должно стоить покупателю письма. Поэтому
     * мусор молча отбрасывается, а не роняет отправку.
     */
    private function isUsableAddress(?string $address): bool
    {
        return is_string($address)
            && trim($address) !== ''
            && filter_var(trim($address), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * В логе не храним содержимое письма: там есть персональные данные и
     * сведения о заказе. Для диагностики достаточно кода в `type`, адресата и
     * списка использованных плейсхолдеров; билетная разметка не раздувает
     * notifications.payload_json.
     *
     * @param  array<string, scalar|Htmlable|null>  $variables
     * @return array{variables: list<string>}
     */
    private function loggableVariables(array $variables): array
    {
        return ['variables' => array_values(array_map('strval', array_keys($variables)))];
    }
}
