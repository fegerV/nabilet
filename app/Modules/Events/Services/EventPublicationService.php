<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Services;

use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Modules\Events\Domain\Event as DomainEvent;
use Nabilet\Modules\Events\Domain\EventPublicationPolicy;
use Nabilet\Modules\Events\Domain\PublicationDecision;
use Nabilet\Modules\Events\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Публикация и отмена события с проверкой правил (ТЗ §10, §13).
 *
 * ЗАЧЕМ ЭТОТ КЛАСС. `EventService::publish()` существовал, но его никто не
 * вызывал: ни роута, ни метода контроллера. Публичного статуса можно было
 * достичь только прислав `status = 'published'` в теле `PUT /api/v1/events/{id}`,
 * а форма это и делала. `EventPublicationPolicy` при этом не вызывалась нигде,
 * то есть опубликовать событие БЕЗ СЕАНСОВ — без даты, без цены и без кнопки
 * «купить» — не мешало ничего.
 *
 * ДЛЯ SEO ЭТО БЫЛО ХУЖЕ, ЧЕМ ПРОСТО БАГ. Такая страница попадает в sitemap
 * (`EventStatus::publiclyVisible()` включает `published`), но не содержит ни
 * одной цены и ни одной даты — это «тонкая страница» (thin content). Google
 * понижает не только её: при систематическом повторении санкция применяется
 * к разделу целиком, и под удар попадает уже вся афиша.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ КЛАСС, А НЕ МЕТОД В `EventService`. Правило живёт в
 * доменном объекте `Domain\Event`, который требует `sessionCount` и
 * `soldUnits` — факты, которых нет в строке `events`. Собрать их из Eloquent и
 * вызвать политику — это отдельная обязанность (трансляция между слоями), и
 * держать её рядом с обычными CRUD-операциями значит смешивать их.
 */
final class EventPublicationService
{
    public function __construct(
        private readonly EventPublicationPolicy $policy
    ) {}

    /**
     * Опубликовать событие.
     *
     * @throws NotFoundError   если событие помечено удалённым
     * @throws ConflictError   если публикация запрещена политикой
     */
    public function publish(Event $event): PublicationDecision
    {
        $decision = $this->policy->publishDecision($this->toDomain($event));

        if (! $decision->isAllowed()) {
            throw new ConflictError(
                $decision->reason ?? 'Публикация события запрещена.',
                strtoupper($decision->verdict),
                ['event_id' => $event->public_id]
            );
        }

        // `NO_CHANGE` — уже опубликовано и момент проставлен. Ничего не пишем:
        // `update()` поднял бы `updated_at`, а `SitemapService` использует это
        // поле как `lastmod`. Форма, сохранённая дважды, заставила бы поисковик
        // переобходить неизменившуюся страницу.
        if (! $decision->requiresWrite()) {
            return $decision;
        }

        $payload = ['status' => DomainEvent::PUBLISHED];

        // Момент публикации проставляем ТОЛЬКО если его нет.
        //
        // Это не косметика: `published_at` — дата выхода на сайт, и она же
        // `lastmod` в карте сайта. Если переписывать её при каждом сохранении,
        // sitemap будет сообщать поисковику «страница обновилась» при каждом
        // нажатии «Опубликовать», даже когда содержимое не менялось.
        //
        // Этот же путь закрывает дефект, который допускала схема: строка с
        // `status = 'published'` и `published_at = NULL` была принята MySQL,
        // и такое событие оказывалось на сайте без ответа на вопрос
        // «с каких пор?». Политика помечает такие строки как требующие записи —
        // и запись их чинит.
        if ($event->published_at === null) {
            $payload['published_at'] = now();
        }

        $event->update($payload);

        return $decision;
    }

    /**
     * Отменить событие.
     *
     * @throws ConflictError если по событию уже есть продажи
     */
    public function cancel(Event $event): PublicationDecision
    {
        $decision = $this->policy->cancelDecision($this->toDomain($event));

        if (! $decision->isAllowed() && $decision->verdict !== PublicationDecision::NO_CHANGE) {
            throw new ConflictError(
                $decision->reason ?? 'Отмена события запрещена.',
                strtoupper($decision->verdict),
                ['event_id' => $event->public_id, 'sold_units' => $this->soldUnits($event)]
            );
        }

        if ($decision->requiresWrite()) {
            $event->update(['status' => 'cancelled']);
        }

        return $decision;
    }

    /**
     * Построить доменный `Event` из строки БД.
     *
     * `sessionCount` и `soldUnits` — то, чего нет в таблице `events`. Оба
     * считаются запросами, и оба нужны политике: без сеансов событие нельзя
     * публиковать, с проданными билетами — нельзя отменять.
     *
     * Продажи считаются по `order_items`, а не по `tickets`: билеты
     * выпускаются после успешной оплаты, поэтому «продано» и «выпущено» могут
     * разойтись на время между вебхуком провайдера и джобой выпуска. Для
     * решения «можно ли отменять» значим факт оплаты.
     */
    private function toDomain(Event $event): DomainEvent
    {
        return new DomainEvent(
            id: $event->id,
            organizationId: (int) $event->organization_id,
            slug: (string) $event->slug,
            status: (string) $event->status,
            publishedAt: $event->published_at?->toDateTimeImmutable(),
            deletedAt: $event->deleted_at?->toDateTimeImmutable(),
            sessionCount: $event->sessions()->count(),
            soldUnits: $this->soldUnits($event),
        );
    }

    /**
     * Сколько единиц продано по событию.
     *
     * ПУТЬ СОЕДИНЕНИЯ. `order_items` НЕ ссылается на сеанс: в таблице есть
     * `order_id` и `inventory_item_id`, а `session_id` живёт в
     * `inventory_items`. Поэтому цепочка такая:
     *
     *   order_items.inventory_item_id → inventory_items.session_id
     *                                                    → sessions.event_id
     *
     * Считать по `orders.event_id` было бы проще, но эта колонка появилась
     * только в миграции 011 и заполняется не для всех исторических заказов —
     * на старых данных счёт получился бы заниженным.
     *
     * ПОЧЕМУ НЕ ПО `tickets`. Билеты выпускаются ПОСЛЕ подтверждения оплаты
     * (джобой через `afterCommit()`), поэтому в окне между вебхуком провайдера
     * и выпуском «оплачено» и «есть билет» расходятся. Для решения «можно ли
     * отменять» значим факт оплаты, а не факт выпуска: отменять событие, за
     * которое уже взяли деньги, нельзя ни секундой раньше.
     *
     * Отменённые и возвращённые заказы исключаются: они не создают
     * обязательств перед покупателем, и блокировать ими отмену события значило
     * бы запереть администратора в состоянии, из которого нет выхода.
     */
    private function soldUnits(Event $event): int
    {
        return (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'order_items.inventory_item_id')
            ->join('sessions', 'sessions.id', '=', 'inventory_items.session_id')
            ->where('sessions.event_id', $event->id)
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->sum('order_items.quantity');
    }
}
