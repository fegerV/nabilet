<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо, собранное из редактируемого шаблона.
 *
 * Тело приходит уже готовым HTML из `notification_templates.body_html`: шаблон
 * правит администратор, поэтому здесь нет blade-представления и нет данных для
 * подстановки — переменные заменены сервисом ДО создания письма. Отдельный
 * Mailable нужен, чтобы письмо было видно в `Mail::fake()` по классу и чтобы
 * его можно было ставить в очередь как обычное письмо.
 */
class TemplateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $htmlBody,
        public readonly ?string $textBody = null,
    ) {}

    public function build(): self
    {
        $this->subject($this->subjectLine)->html($this->htmlBody);

        // Plain-text альтернатива: почтовые клиенты и спам-фильтры хуже
        // относятся к письмам без текстовой части. Кладём её вторым вариантом
        // через Symfony-сообщение, потому что `text()` принимает имя
        // представления, а у нас готовая строка.
        if ($this->textBody !== null && $this->textBody !== '') {
            $text = $this->textBody;
            $this->withSymfonyMessage(static function ($message) use ($text): void {
                // Symfony Email::addPart добавляет вложение, а не альтернативу.
                // text() строит multipart/alternative с HTML-версией.
                $message->text($text, 'utf-8');
            });
        }

        return $this;
    }
}
