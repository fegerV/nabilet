<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Services;

use Nabilet\Modules\Notifications\Domain\NotificationStatus;
use Nabilet\Modules\Notifications\Models\Notification;
use Nabilet\Modules\Notifications\Models\NotificationTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Nabilet\Modules\Notifications\Mail\TemplateMail;
use Throwable;

/**
 * Письма о доступе к аккаунту (сброс пароля, подтверждение адреса).
 *
 * Отдельный сервис, а не вызов `TransactionalMailService` с кодом заказа: у
 * писем о заказе другая модель переменных (мероприятие, сумма, билеты) и
 * другой вызывающий (обсервер статуса). Общий у них ровно один шаг — отправка
 * уже собранного `TemplateMail`, и его дублирование здесь дешевле, чем
 * протаскивание «пустых» переменных заказа через подстановку: шаблон с
 * `{{order_number}}` не должен мочь случайно отрендериться как письмо о пароле.
 *
 * ПОИСК ШАБЛОНА И ПОДСТАНОВКА ПЕРЕМЕННЫХ — ОБЩИЕ, в
 * `NotificationTemplateRenderer`. Раньше они были скопированы сюда целиком, и
 * копия отстала: в ней не было ветки `Htmlable` и защиты от нескалярных
 * значений. Списки переменных при этом остаются раздельными (см. выше), так
 * что защита от чужого плейсхолдера не ослаблена — рендерер подставляет ровно
 * то, что ему передали.
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
    /** Ссылка на владельца: литералы `'email'`/`'ru'` жили здесь и в
     *  `TransactionalMailService` независимо, то есть расходились молча. */
    public const CHANNEL = NotificationTemplateRenderer::CHANNEL;
    public const LOCALE = NotificationTemplateRenderer::LOCALE;

    public function __construct(
        private readonly NotificationTemplateRenderer $templates,
    ) {}

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

        $body = $this->templates->render($template, $variables);

        $log = Notification::query()->create([
            'user_id' => $userId,
            'channel' => self::CHANNEL,
            'type' => $code,
            'recipient' => $recipient,
            'status' => NotificationStatus::QUEUED,
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

            $log->update(['status' => NotificationStatus::SENT, 'sent_at' => now()]);

            return true;
        } catch (Throwable $e) {
            // SMTP-клиент иногда включает адрес получателя в сообщение ошибки.
            // Убираем его из текста: адрес уже есть в колонке `recipient`.
            $safeMessage = str_ireplace($recipient, '[адрес скрыт]', $e->getMessage());

            $log->update([
                'status' => NotificationStatus::FAILED,
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
        return $this->templates->find($code);
    }

    /**
     * Готовая ссылка для письма.
     *
     * Токен в query-параметре — обычная практика для ссылок из письма: он
     * попадает в историю браузера и логи веб-сервера, поэтому срок жизни
     * ограничен (`AccountTokenService::DEFAULT_TTL`). Хранить его в пути не
     * лучше: логи его всё равно увидят.
     *
     * ПОЧЕМУ ПУТЬ НАСТОЯЩИЙ, А НЕ ПОСЛЕ `#`
     *   С переходом витрины на history-роутинг (`createWebHistory` в
     *   `resources/js/router/index.ts`) сервер отдаёт SPA-оболочку на белом
     *   списке путей (`routes/web.php`), и `/reset-password?...` открывается
     *   напрямую. История вопроса: до 1.2.0 витрина жила на hash-истории,
     *   серверного маршрута `/reset-password` не существовало, и ссылка
     *   `<base>/reset-password?...` вела в 404 — поэтому ссылки собирались
     *   hash-формой `/#/reset-password?...`. Старые письма продолжает
     *   принимать мост в `router/index.ts` (переписывает фрагмент в путь
     *   до старта роутера).
     *
     * Базовый URL берёт `url()` — а не `rtrim(config('app.url'),'/')`, как
     * стояло здесь. Ручная склейка корня повторялась в четырёх местах
     * (`ReferralService`, `NotifyRaffleWinnersJob`, `TicketGeneratorService` и
     * здесь), и каждое повторяло одно правило: убрать завершающий слэш,
     * приписать путь. `url()` делает это сам, а `AccountRecoveryTest`
     * сравнивает результат именно с формой `url('/reset-password')`.
     *
     * Путь задаётся здесь, а не в шаблоне: шаблон не должен знать
     * маршрутизацию SPA, иначе смена роутинга ломала бы уже отправленные
     * письма.
     */
    public function actionUrl(string $path, string $token, string $email): string
    {
        // Путь — НАСТОЯЩИЙ адрес витрины (history-роутинг): сервер отдаёт
        // SPA-оболочку на белом списке путей (routes/web.php), поэтому
        // `/reset-password?...` открывается напрямую. Раньше был hash-адрес
        // `/#/reset-password?...` — живой формат ссылок до 1.2.0, мост в
        // `router/index.ts` по-прежнему принимает его из старых писем.
        $spaPath = str_starts_with($path, '/') ? $path : '/' . $path;

        return url($spaPath) . '?' . http_build_query([
            'token' => $token,
            'email' => $email,
        ]);
    }
}
