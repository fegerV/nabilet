<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Services;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Nabilet\Modules\Notifications\Mail\TemplateMail;
use Throwable;

/**
 * Письма о доступе к аккаунту (сброс пароля, подтверждение адреса).
 *
 * Отдельный сервис, а не вызов `TransactionalMailService` с кодом заказа:
 * у писем о заказе другая модель переменных (мероприятие, сумма, билеты) и
 * другой вызывающий (обсервер статуса). Общий у них ровно один шаг — отправка
 * уже собранного `TemplateMail`, и его дублирование здесь дешевле, чем
 * протаскивание «пустых» переменных заказа через подстановку: шаблон с
 * `{{order_number}}` не должен мочь случайно отрендериться как письмо о пароле.
 *
 * ШАБЛОНЫ РЕДАКТИРУЕМЫЕ, как и у заказов: текст лежит в `notification_templates`
 * по коду из `AccountNotificationCodes`, поэтому администратор правит его без
 * деплоя. Если шаблон не найден или выключен — письмо НЕ отправляется, и это
 * осознанно: молча подставить встроенный текст значило бы, что отключение
 * шаблона в админке не работает.
 *
 * ССЫЛКА СОБИРАЕТСЯ ЗДЕСЬ, а не в шаблоне: `token` — секрет, и склеивать его с
 * базовым URL в тексте шаблона означало бы, что администратор может случайно
 * (или намеренно) направить ссылку на чужой домен. Шаблон получает готовый
 * `{{action_url}}`, а `{{token}}` в него не попадает.
 */
class AccountMailService
{
    public const CHANNEL = 'email';
    public const LOCALE = 'ru';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * Отправить письмо по коду шаблона.
     *
     * @param  array<string, scalar|null>  $variables
     */
    public function send(string $code, string $recipient, array $variables, ?int $userId = null): bool
    {
        if (trim($recipient) === '') {
            return false;
        }

        $template = $this->findTemplate($code);

        if ($template === null) {
            // Не ошибка доставки: шаблон выключен или отсутствует. Пишем в лог
            // без адреса — он и так известен вызывающему, а в логе ему не место.
            Log::warning('Шаблон письма о доступе к аккаунту не найден или выключен.', [
                'code' => $code,
            ]);

            return false;
        }

        $body = $this->renderTemplate($template, $variables);

        $log = Notification::query()->create([
            'user_id' => $userId,
            'channel' => self::CHANNEL,
            'type' => $code,
            'recipient' => $recipient,
            'status' => self::STATUS_QUEUED,
            // Ни содержимое письма, ни токен в лог не пишем: `payload_json` — это
            // диагностика, а не хранилище секретов. Достаточно кодов и адресата.
            'payload_json' => ['variables' => array_values(array_map('strval', array_keys($variables)))],
        ]);

        try {
            Mail::to($recipient)->send(new TemplateMail(
                subjectLine: $body['subject'],
                htmlBody: $body['html'],
                textBody: $body['text'],
            ));

            $log->update(['status' => self::STATUS_SENT, 'sent_at' => now()]);

            return true;
        } catch (Throwable $e) {
            // SMTP-клиент иногда включает адрес получателя в сообщение ошибки.
            // Убираем его из текста: адрес уже есть в колонке `recipient`.
            $safeMessage = str_ireplace($recipient, '[адрес скрыт]', $e->getMessage());

            $log->update([
                'status' => self::STATUS_FAILED,
                'error_message' => $safeMessage,
            ]);

            Log::error('Не удалось отправить письмо о доступе к аккаунту.', [
                'code' => $code,
                'notification_id' => $log->id,
                'exception' => $e::class,
                'message' => $safeMessage,
            ]);

            throw $e;
        }
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
     * Готовая ссылка для письма.
     *
     * Токен в query-параметре — обычная практика для ссылок из письма: он
     * попадает в историю браузера и логи веб-сервера, поэтому срок жизни
     * ограничен (`AccountTokenService::DEFAULT_TTL`). Хранить его в пути или в
     * фрагменте не лучше: логи его всё равно увидят.
     *
     * Базовый URL берётся из `app.url`, а путь задаётся здесь — шаблон не должен
     * знать маршрутизацию SPA, иначе смена хеш-роутинга ломала бы уже
     * отправленные письма.
     */
    public function actionUrl(string $path, string $token, string $email): string
    {
        $base = rtrim((string) config('app.url', ''), '/');

        return $base . $path . '?' . http_build_query([
            'token' => $token,
            'email' => $email,
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @return array{subject: string, html: string, text: string}
     */
    private function renderTemplate(NotificationTemplate $template, array $variables): array
    {
        return [
            'subject' => $this->renderText((string) ($template->subject ?? ''), $variables),
            'html' => $this->renderHtml((string) ($template->body_html ?? ''), $variables),
            'text' => $this->renderText((string) ($template->body_text ?? ''), $variables),
        ];
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    private function renderHtml(string $body, array $variables): string
    {
        foreach ($variables as $name => $value) {
            $body = str_replace(
                '{{' . $name . '}}',
                $value === null ? '' : e((string) $value),
                $body,
            );
        }

        return $body;
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    private function renderText(string $body, array $variables): string
    {
        foreach ($variables as $name => $value) {
            $body = str_replace('{{' . $name . '}}', $value === null ? '' : (string) $value, $body);
        }

        return $body;
    }
}
