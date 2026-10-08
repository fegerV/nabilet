<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Services;

use Illuminate\Support\Facades\Log;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Models\TicketTemplate;

/**
 * Какой макет билета положен этому билету.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ СЕРВИС, А НЕ МЕТОД ГЕНЕРАТОРА
 *
 * Решение «какой макет» — это правило домена со своей цепочкой фолбэков, и
 * его надо проверять отдельно от вёрстки письма. Пока оно лежало бы внутри
 * `TicketGeneratorService`, единственным способом протестировать фолбэк было
 * бы собрать письмо целиком.
 *
 * РЕЗОЛВ ИДЁТ ОТ ЗАКАЗА, А НЕ ОТ ПОЛЬЗОВАТЕЛЯ
 *
 * Это главное свойство, и оно не косметическое. Билет покупает в том числе
 * аноним: `orders.user_id = NULL`, контакт — только `customer_email`. Если бы
 * резолв зависел от пользователя (`$user->organization_id`, «шаблон из
 * профиля»), аноним не получил бы оформленного билета вообще — молча, потому
 * что оба потребителя (`OrderNotificationData`, `NewsletterService`) глотают
 * `Throwable` и делают `continue`. Поэтому источник — `tickets.event_id`, а
 * при его отсутствии `tickets.session_id` → `sessions.event_id`: ровно тот же
 * путь, которым `TicketService::issueTicketsForOrder()` определяет событие при
 * выпуске, и оба поля NOT NULL.
 *
 * ЧТО ЗНАЧИТ «ШАБЛОН УДАЛЁН»
 *
 * `events.ticket_template_id` объявлена с `ON DELETE SET NULL`, поэтому
 * удаление макета обнуляет ссылку — это и есть штатный переход на макет по
 * умолчанию, а не ошибка. Отдельно обрабатывается только гонка: ссылку прочли
 * до удаления, а строку запрашиваем после. Результат тот же — фолбэк.
 *
 * ФОЛБЭК: МАКЕТ ПО УМОЛЧАНИЮ
 *
 * «Шаблон по умолчанию» — первый активный макет организации. Клиент
 * («Сургут-Концерт») сам организатор, поэтому дилеммы «шаблон мероприятия vs
 * шаблон организации» не возникает: любой макет — их макет (см.
 * `outputs/ticket-designer-delivery-plan.md` §0).
 */
class TicketTemplateResolver
{
    /**
     * Макет для билета, либо `null`, если оформлять нечем.
     *
     * `null` — не ошибка: билет уходит в стандартном виде. Именно поэтому
     * метод возвращает nullable-тип, а не бросает исключение.
     */
    public function resolveForTicket(Ticket $ticket): ?TicketTemplate
    {
        $eventId = $this->eventIdForTicket($ticket);

        if ($eventId === null) {
            return null;
        }

        $organizationId = Event::query()
            ->whereKey($eventId)
            ->value('organization_id');

        $organizationId = $organizationId === null ? null : (int) $organizationId;

        return $this->assignedTemplate($eventId, $organizationId)
            ?? $this->defaultTemplate($organizationId);
    }

    /**
     * Событие билета: `tickets.event_id`, иначе через сеанс.
     *
     * Второй путь нужен не для красоты: колонки NOT NULL, но строки,
     * созданные до того, как `TicketService` научился выводить событие из
     * сеанса, могли остаться с пустым `event_id` в старых базах.
     */
    private function eventIdForTicket(Ticket $ticket): ?int
    {
        $eventId = $ticket->event_id;

        if ($eventId === null && $ticket->session_id !== null) {
            $eventId = Session::query()
                ->whereKey($ticket->session_id)
                ->value('event_id');
        }

        return $eventId === null ? null : (int) $eventId;
    }

    /**
     * Макет, назначенный мероприятию.
     */
    private function assignedTemplate(int $eventId, ?int $organizationId): ?TicketTemplate
    {
        $templateId = Event::query()
            ->whereKey($eventId)
            ->value('ticket_template_id');

        if ($templateId === null) {
            return null;
        }

        $template = TicketTemplate::query()->whereKey((int) $templateId)->first();

        if ($template === null) {
            // Ссылку прочли, строку запросили — а её уже удалили. Штатный
            // фолбэк, а не исключение.
            return null;
        }

        if (! $this->belongsToOrganization($template, $organizationId)) {
            // Макет чужой организации. Отдавать его нельзя: это была бы
            // утечка чужого оформления (и, потенциально, чужого бренда) в
            // письмо нашему покупателю. Говорим громко и падаем на дефолт.
            Log::warning('Макет билета принадлежит другой организации — используем макет по умолчанию.', [
                'template_id' => $template->id,
                'template_organization_id' => $template->organization_id,
                'event_organization_id' => $organizationId,
            ]);

            return null;
        }

        return $template;
    }

    /**
     * Макет по умолчанию: первый активный макет организации.
     *
     * Сортировка по `id` — не «первый попавшийся»: она детерминирована, иначе
     * два письма одного заказа могли бы разойтись оформлением, а тест на
     * фолбэк стал бы нестабильным.
     */
    private function defaultTemplate(?int $organizationId): ?TicketTemplate
    {
        if ($organizationId === null) {
            return null;
        }

        return TicketTemplate::query()
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * Макет принадлежит той же организации, что и мероприятие.
     *
     * `organization_id = NULL` у макета считается «общим»: FK
     * `fk_ticket_templates_org` объявлен с `ON DELETE SET NULL`, поэтому NULL
     * здесь означает «организацию удалили», а не «макет чужой». Считать такой
     * макет чужим значило бы навсегда потерять оформление при удалении
     * организации.
     */
    private function belongsToOrganization(TicketTemplate $template, ?int $organizationId): bool
    {
        if ($organizationId === null) {
            return true;
        }

        return $template->organization_id === null
            || (int) $template->organization_id === $organizationId;
    }
}
