<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Services;

use App\Models\Notification;
use App\Models\NotificationTemplate;
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
 * ПОДСТАНОВКА ПЕРЕМЕННЫХ — ЕДИНСТВЕННОЕ МЕСТО, ГДЕ ДАННЫЕ ПОЛЬЗОВАТЕЛЯ
 * ПОПАДАЮТ В HTML. Имя покупателя вводится им самим при оформлении заказа,
 * поэтому подставлять его в HTML как есть нельзя: в письме появится чужая
 * разметка. Значения экранируются, кроме тех, что пришли как `Htmlable` —
 * именно так передаётся уже собранный блок билетов (`tickets_html`), который
 * экранировать нельзя, иначе покупатель увидит теги вместо QR-кода.
 *
 * В текстовой версии письма экранирование не нужно и даже вредно: `&amp;`
 * читается как мусор, поэтому там значения вставляются как есть.
 *
 * Сбой транспорта пишется в лог и в запись `notifications` со статусом
 * `failed`, после чего исключение пробрасывается обратно в очередь. Письмо
 * вызывается только из SendOrderNotificationJob, который уже ушёл
 * `afterCommit()`; поэтому транспортный сбой не отменяет оплаченный заказ,
 * зато Laravel сможет повторить его по настройке `tries`/`backoff`.
 */
class TransactionalMailService
{
    public const CHANNEL = 'email';
    public const LOCALE = 'ru';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * Переменные, доступные в шаблоне. Список показывается администратору в
     * редакторе шаблонов, чтобы не угадывать имена.
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
        ];
    }

    /**
     * @param  string  $code  код шаблона, например `order.paid`
     * @param  array<string, scalar|Htmlable|null>  $variables
     */
    public function send(string $code, string $recipient, array $variables, ?int $userId = null): bool
    {
        if (trim($recipient) === '') {
            // Некуда отправлять — не считаем это ошибкой письма: у заказа может
            // не быть e-mail (оформление без контакта), и это отдельная проблема.
            return false;
        }

        $template = $this->findTemplate($code);

        if ($template === null) {
            Log::warning('Шаблон письма не найден или выключен — письмо не отправлено.', [
                'code' => $code,
            ]);

            return false;
        }

        $body = $this->render($template, $variables);

        $log = Notification::query()->create([
            'user_id' => $userId,
            'channel' => self::CHANNEL,
            'type' => $code,
            'recipient' => $recipient,
            'status' => self::STATUS_QUEUED,
            'payload_json' => $this->loggableVariables($variables),
        ]);

        try {
            Mail::to($recipient)->send(new TemplateMail(
                subjectLine: $body['subject'],
                htmlBody: $body['html'],
                textBody: $body['text'],
            ));

            $log->update([
                'status' => self::STATUS_SENT,
                'sent_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            // SMTP-клиент иногда включает адрес получателя в сообщение ошибки.
            // Убираем его и из логов, и из Notification.error_message: адрес уже
            // хранится в recipient-колонке, дублировать PII в свободном тексте не надо.
            $safeMessage = str_ireplace($recipient, '[адрес скрыт]', $e->getMessage());

            $log->update([
                'status' => self::STATUS_FAILED,
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

        return $template === null ? null : $this->renderTemplate($template, $variables);
    }

    public function findTemplate(string $code): ?NotificationTemplate
    {
        return NotificationTemplate::query()
            ->where('code', $code)
            ->where('channel', self::CHANNEL)
            ->where('locale', self::LOCALE)
            ->where('active', true)
            ->first();
    }

    /**
     * @param  array<string, scalar|Htmlable|null>  $variables
     * @return array{subject: string, html: string, text: string}
     */
    public function renderTemplate(NotificationTemplate $template, array $variables): array
    {
        return [
            'subject' => $this->renderText((string) ($template->subject ?? ''), $variables),
            'html' => $this->renderHtml((string) ($template->body_html ?? ''), $variables),
            'text' => $this->renderText((string) ($template->body_text ?? ''), $variables),
        ];
    }

    /**
     * Подстановка в HTML: значения экранируются, `Htmlable` вставляется как есть.
     *
     * @param  array<string, scalar|Htmlable|null>  $variables
     */
    private function renderHtml(string $body, array $variables): string
    {
        if ($body === '') {
            return '';
        }

        foreach ($variables as $name => $value) {
            $replacement = match (true) {
                $value instanceof Htmlable => $value->toHtml(),
                $value === null => '',
                is_bool($value) => $value ? '1' : '',
                is_scalar($value) => e((string) $value),
                // Массивы и объекты в HTML не подставляем: это всегда ошибка
                // сборки данных, и пустое место заметнее, чем «Array».
                default => '',
            };

            $body = str_replace('{{' . $name . '}}', $replacement, $body);
        }

        return $body;
    }

    /**
     * @param  array<string, scalar|Htmlable|null>  $variables
     */
    private function renderText(string $body, array $variables): string
    {
        if ($body === '') {
            return '';
        }

        foreach ($variables as $name => $value) {
            $replacement = match (true) {
                $value instanceof Htmlable => strip_tags($value->toHtml()),
                $value === null => '',
                is_bool($value) => $value ? '1' : '',
                is_scalar($value) => (string) $value,
                default => '',
            };

            $body = str_replace('{{' . $name . '}}', $replacement, $body);
        }

        return $body;
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
