<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nabilet\Modules\Notifications\Services\AccountMailService;

/**
 * Письмо о доступе к аккаунту — в очередь, а не синхронно.
 *
 * Причина та же, что у писем о заказах: SMTP — это сеть, и её latency не должна
 * попадать в HTTP-ответ. `POST /auth/forgot-password` обязан отвечать 204
 * независимо от того, жив ли почтовый сервер, — иначе наличие аккаунта читается
 * по времени ответа или по коду ошибки.
 *
 * Секрет (токен) передаётся в джобе, а не читается из письма в `handle()`:
 * джоба ставится сразу после выпуска токена, и повторный выпуск запрещён — иначе
 * в очереди оказались бы два разных токена на одну ссылку.
 */
class SendAccountNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Ошибка SMTP повторяется независимо от запроса пользователя. */
    public int $tries = 3;

    public array $backoff = [60, 300];

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function __construct(
        public readonly string $code,
        public readonly string $recipient,
        public readonly array $variables,
        public readonly ?int $userId = null,
    ) {}

    public function handle(AccountMailService $mail): void
    {
        $mail->send($this->code, $this->recipient, $this->variables, $this->userId);
    }
}
