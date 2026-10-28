<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Services;

use Carbon\CarbonInterface;
use Nabilet\Core\Support\RuText;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Loyalty\Models\EventFollow;
use Nabilet\Modules\Sessions\Models\Session;

/**
 * Данные сеанса и подписчика для подстановки в шаблон письма.
 *
 * Аналог `OrderNotificationData`, но в миниатюре: билетов здесь нет и быть не
 * может — письмо подписчику зовёт на мероприятие, а не выдаёт доступ. Все
 * значения — скаляры, `Htmlable` не нужен: единственная ссылка (`action_url`)
 * собирается здесь и вставляется в шаблон как текст, а не как готовая кнопка.
 *
 * ССЫЛКА СОБИРАЕТСЯ ЗДЕСЬ, а не в шаблоне — по той же причине, что в
 * `AccountMailService`: шаблон редактирует администратор, и адресат ссылки не
 * должен зависеть от его правки. Витрина — SPA с hash-роутером, поэтому путь
 * идёт фрагментом: `<база>/#/event/<slug>` (маршрут `event` в
 * `resources/js/router/index.ts`).
 */
final class FollowNotificationData
{
    /**
     * @return array<string, string>
     */
    public function build(EventFollow $follow, Session $session): array
    {
        $session->loadMissing('event', 'venue');
        $event = $session->event;

        return [
            'customer_name' => $this->customerName($follow),
            'event_name' => (string) ($event?->title ?? ''),
            // Та же переменная и то же значение, что в заказных письмах: дата
            // СЕАНСА. Одно имя на один смысл во всех шаблонах — администратор
            // не должен угадывать, чем `session_date` отличается от `event_date`.
            // «То же значение» теперь обеспечено вызовом `RuText::stamp()`, а не
            // совпадением двух литералов `d.m.Y H:i` в трёх файлах.
            'event_date' => RuText::stamp($session->starts_at) ?? '',
            'venue_name' => (string) ($session->venue?->name ?? ''),
            'action_url' => $this->actionUrl($event),
            'sales_note' => $this->salesNote($session),
        ];
    }

    private function customerName(EventFollow $follow): string
    {
        $follow->loadMissing('user');

        $name = trim((string) ($follow->user?->name ?? ''));

        // 'Зритель', а не 'User' из аксессора модели: письмо на русском, и
        // «Здравствуйте, User!» выглядит сломанным.
        return $name !== '' && $name !== 'User' ? $name : 'Зритель';
    }

    private function actionUrl(?Event $event): string
    {
        $slug = trim((string) ($event?->slug ?? ''));

        if ($slug === '') {
            // Мероприятие без слага — завести такое может только импорт или
            // черновик. Ссылки нет, и письмо уходит без неё: пустой `href`
            // вёрстка письма превращает в кнопку, ведущую «в текущую страницу».
            return '';
        }

        $base = rtrim((string) config('app.url', ''), '/');

        return $base . '/#/event/' . $slug;
    }

    /**
     * Готовая фраза о продажах — то, ради чего письмо отправлено.
     *
     * `isSellableAt()` — тот же гейт, что продаёт билеты: и оперативный
     * переключатель (`status = on_sale`), и окно (`sales_start_at`). Если
     * гейт говорит «продаём», фраза говорит «покупайте»; иначе она честно
     * называет дату открытия, а при её отсутствии — «скоро».
     */
    private function salesNote(Session $session): string
    {
        if ($session->isSellableAt()) {
            return 'Билеты уже в продаже.';
        }

        $opensAt = $session->sales_start_at;

        if ($opensAt instanceof CarbonInterface && $opensAt->isFuture()) {
            return 'Продажи откроются ' . RuText::stamp($opensAt) . '.';
        }

        return 'Продажи скоро начнутся.';
    }
}
