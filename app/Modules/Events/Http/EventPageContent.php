<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http;

use Nabilet\Core\Support\Money;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Models\Event;

/**
 * Тексты страницы события: заголовок, описание, серверный блок и площадка.
 *
 * Вынесено из `EventPageController` вторым шагом разбиения (первым была
 * `EventPageShell` — сборка самого HTML-документа). Контроллер разбирал запрос,
 * собирал разметку, форматировал даты, цены и адреса — и всё это в одном файле
 * на 1480 строк, где правка формулировки требовала чтения маршрутизации.
 *
 * ЧТО ЗДЕСЬ ВАЖНО ПОМНИТЬ
 *   Тексты зависят от ЖИЗНЕННОГО ЦИКЛА события: прошедшее и отменённое не
 *   должны обещать покупку билета. Поэтому `buildTitle()`/`buildDescription()`
 *   читают статус, а `statusNote()`/`statusBanner()` существуют отдельно —
 *   сниппет сообщает статус, страница рассказывает о событии.
 *   Ровно один `<h1>` на страницу в любом статусе: заголовок печатает либо
 *   блок статуса, либо контентный блок (см. `bodyFallback()`).
 *
 * Зависимостей от репозиториев и сервисов нет: только модель события, её
 * связи (`artists`, `sessions.venue`) и глобальные `route()`/`url()`.
 */
final class EventPageContent
{
    /**
     * Площадка для JSON-LD.
     *
     * `venue_id` объявлен NOT NULL в `sessions`, поэтому у любого валидного
     * события площадка есть. Пустые строки здесь — не «безопасный дефолт»:
     * schema.org/Event требует непустой `location`, и разметка с
     * `addressLocality: ""` отбраковывается валидатором Google.
     *
     * @return array<string, mixed>
     */
    public function venuePayload(Event $event, ?object $session): array
    {
        $venue = $session?->venue ?? $event->sessions->first()?->venue;

        // `region` и `country` в таблице `venues` есть, `postal_code` — НЕТ
        // (проверено по `nabilet_core_spec/migrations.sql`). `StructuredDataService`
        // читает `postal_code` через `??`, поэтому отсутствие ключа безопасно —
        // в разметку уйдёт `addressCountry: 'RU'`, а индекса «несуществующего»
        // поля в JSON-LD не будет.
        return [
            'name'    => $venue?->name ?? '',
            'address' => $venue?->address ?? '',
            'city'    => $venue?->city ?? '',
            'region'  => $venue?->region ?? '',
            'country' => $venue?->country ?? 'RU',
        ];
    }
    /**
     * Заголовок страницы с учётом жизненного цикла (аудит P-05/P-17).
     *
     * `seo_title` из БД побеждает всегда — его написал человек, и спорить с ним
     * автоматика не должна. Но значение ПО УМОЛЧАНИЮ обязано зависеть от
     * статуса: «… — купить билеты» на странице прошедшего или отменённого
     * концерта — это обещание, которого страница не выполняет. Сниппет обещает
     * покупку, пользователь приходит и не находит её; для поисковика это
     * расхождение сниппета и содержимого, то есть повод понизить доверие к
     * выдаче по этому сайту.
     *
     * ДЛИНА НЕ ОБРЕЗАЕТСЯ НАМЕРЕННО (аудит P-21). Название мероприятия в
     * заголовке важнее его длины: поисковик обрезает длинный title только при
     * ПОКАЗЕ и не понижает за это страницу, а вот потерянное из середины название
     * («Новогодний концерт в Крем…») бьёт по релевантности запросу. Обрезка
     * описания — другое дело, там она безопасна, и она делается (см.
     * `buildDescription()`).
     */
    public function buildTitle(Event $event): string
    {
        if (is_string($event->seo_title) && trim($event->seo_title) !== '') {
            return trim($event->seo_title);
        }

        return match ($event->status) {
            EventStatus::COMPLETED => sprintf('%s — мероприятие состоялось', $event->title),
            EventStatus::CANCELLED => sprintf('%s — мероприятие отменено', $event->title),
            EventStatus::ARCHIVED => sprintf('%s — архив мероприятий', $event->title),
            default => sprintf('%s — купить билеты', $event->title),
        };
    }
    /**
     * Фраза о статусе события — для описания, сниппета и видимого блока.
     *
     * `null` для живых статусов: там сообщать нечего, и «Мероприятие состоится»
     * в описании было бы шумом в каждом сниппете.
     */
    private function statusNote(Event $event, ?object $session = null): ?string
    {
        $startsAt = $session?->starts_at;

        return match ($event->status) {
            EventStatus::COMPLETED => $startsAt === null
                ? 'Мероприятие состоялось. Билеты больше не продаются.'
                : sprintf(
                    'Мероприятие состоялось %s. Билеты больше не продаются.',
                    $startsAt->format('d.m.Y'),
                ),
            EventStatus::CANCELLED => 'Мероприятие отменено. Билеты не продаются.',
            default => null,
        };
    }
    /**
     * Видимый серверный блок статуса внутри `#app` (аудит P-05/P-17).
     *
     * ЗАЧЕМ. Метаданные и JSON-LD отдавались сервером, а ВЕСЬ видимый текст
     * появлялся только после исполнения JavaScript. Краулер без JS — а
     * социальные парсеры не исполняют JS никогда — видел страницу прошедшего или
     * отменённого концерта как обычную витрину покупки: разметка говорила
     * `EventCancelled`, а видимого текста об отмене не было вовсе. Расхождение
     * разметки и видимого контента поисковик трактует как введение в
     * заблуждение.
     *
     * ПОЧЕМУ ВНУТРИ `#app`. Vue при монтировании очищает контейнер, поэтому
     * пользователю этот блок не показывается — его заменяет приложение. Для
     * краулера без JS он остаётся. Так «догоняющий» рендер не требует серверного
     * рендеринга всего приложения.
     *
     * @return string уже экранированный HTML
     */
    private function statusBanner(Event $event, ?object $session = null): string
    {
        $headline = match ($event->status) {
            EventStatus::COMPLETED => 'Мероприятие состоялось',
            EventStatus::CANCELLED => 'Мероприятие отменено',
            default => null,
        };

        if ($headline === null) {
            return '';
        }

        return sprintf(
            '<div class="seo-status-fallback" data-event-status="%s">'
            . '<h1>%s</h1>'
            . '<p>%s</p>'
            . '</div>',
            htmlspecialchars((string) $event->status, ENT_QUOTES),
            htmlspecialchars(sprintf('%s «%s»', $headline, (string) $event->title), ENT_QUOTES),
            htmlspecialchars((string) $this->statusNote($event, $session), ENT_QUOTES),
        );
    }
    /**
     * Весь видимый серверный текст внутри `#app` (аудит P-05/P-17/P-20).
     *
     * ДВА БЛОКА, А НЕ ОДИН, И ЭТО НАМЕРЕННО. Блок статуса существует только у
     * терминальных состояний и отвечает на вопрос «что случилось»; контентный
     * блок есть у всех и отвечает на вопрос «что это». Сливать их в один
     * пришлось бы ценой заголовка `<h1>`: у терминального состояния он несёт
     * статус («Мероприятие состоялось «X»»), у живого — само название. Два
     * `<h1>` на странице — это не «больше контекста», а два конкурирующих
     * заголовка в выдаче.
     *
     * Поэтому `contentFallback()` получает флаг: заголовок печатает он, только
     * если блока статуса нет. Ровно один `<h1>` на страницу в любом статусе.
     *
     * @param  array<string, mixed>  $offer
     * @return string уже экранированный HTML
     */
    public function bodyFallback(Event $event, ?object $session, array $offer): string
    {
        $banner = $this->statusBanner($event, $session);

        return $banner . $this->contentFallback($event, $session, $offer, $banner === '');
    }
    /**
     * Контентный серверный блок (аудит P-20).
     *
     * ЗАЧЕМ. Метаданные, JSON-LD и (после P-05/P-17) блок статуса отдавались
     * сервером, но у живого события ВЕСЬ видимый текст появлялся только после
     * исполнения JavaScript: в HTML лежал пустой `<div id="app">`. Google такой
     * контент отрисует с задержкой, Яндекс — хуже, а парсеры соцсетей не
     * исполняют JS никогда, поэтому карточка ссылки строилась из одних
     * метатегов. Серверный текст снимает зависимость индексации от рендеринга.
     *
     * ПОЧЕМУ ВНУТРИ `#app`. Vue при монтировании очищает контейнер, поэтому
     * пользователь этот блок не видит — его заменяет приложение. Это не
     * «догоняющий» рендер всего приложения, а ровно тот минимум, которого
     * краулеру не хватает: заголовок, дата, площадка, лид и цена.
     *
     * ДУБЛИРОВАНИЕ С МЕТАДАННЫМИ — НЕ ОШИБКА. Описание здесь берётся из
     * `short_description`/`description` (человеческий текст события), а не из
     * `buildDescription()`, который для терминальных состояний подменяет текст
     * статусной фразой ради сниппета. Страница и сниппет решают разные задачи:
     * сниппет должен сообщить статус, страница — рассказать о событии.
     *
     * @param  array<string, mixed>  $offer
     * @return string уже экранированный HTML
     */
    private function contentFallback(
        Event $event,
        ?object $session,
        array $offer,
        bool $withHeading,
    ): string {
        $parts = [];

        // Заголовок печатается только когда блока статуса нет — см. `bodyFallback()`.
        if ($withHeading) {
            $parts[] = sprintf('<h1>%s</h1>', htmlspecialchars((string) $event->title, ENT_QUOTES));
        }

        $lines = [
            'seo-fallback__when'  => $this->sessionLine($event, $session),
            'seo-fallback__where' => $this->venueLine($event, $session),
            'seo-fallback__lead'  => $this->leadText($event),
            // Заметка «билетов нет» выводится только когда блока статуса нет:
            // у терминальных состояний он уже сказал, что продажа закрыта, и
            // «Все билеты проданы» рядом с «мероприятие отменено» было бы
            // повтором, который читается как противоречие.
            'seo-fallback__price' => $this->priceLine($offer, announceSoldOut: $withHeading),
        ];

        foreach ($lines as $class => $text) {
            if ($text !== null) {
                $parts[] = sprintf(
                    '<p class="%s">%s</p>',
                    $class,
                    htmlspecialchars($text, ENT_QUOTES)
                );
            }
        }

        // Исполнители — ссылки на страницы артистов (стратегия SEO, B-1).
        //
        // ЗАЧЕМ ИМЕННО ССЫЛКА. Страница артиста существует под запрос «Би-2
        // билеты», и попасть на неё краулер может двумя путями: из
        // `sitemap-artists.xml` и по ссылке. Карта лишь сообщает о существовании
        // адреса — вес по ней не перетекает. Ссылка со страницы события, самого
        // обходимого типа страниц, делает артиста частью обычного обхода, а не
        // отдельным островом, известным только карте.
        //
        // НЕ В JSON-LD, А В ТЕЛЕ. `performer` в разметке события выводится
        // только при наличии данных и служит для расширенного сниппета; ходить
        // по ссылкам робот умеет из HTML. Одно другого не заменяет, поэтому
        // ссылка живёт здесь.
        $artists = $this->artistLinks($event);

        if ($artists !== '') {
            $parts[] = $artists;
        }

        // Блок без содержания не выводится: пустой `<div>` — это тот же «тонкий
        // контейнер», от которого избавляемся, только с классом.
        if ($parts === []) {
            return '';
        }

        // Ссылка на афишу. Краулер без JS не может пройти по SPA-навигации, а
        // серверный блок — единственное место, где для него существует переход
        // дальше этой страницы.
        $parts[] = sprintf(
            '<p class="seo-fallback__links"><a href="%s">Афиша всех мероприятий</a></p>',
            htmlspecialchars(url('/'), ENT_QUOTES)
        );

        return sprintf(
            '<div class="seo-content-fallback" data-event-status="%s">%s</div>',
            htmlspecialchars((string) $event->status, ENT_QUOTES),
            implode('', $parts)
        );
    }
    /**
     * Ссылки на артистов события для серверного блока (стратегия SEO, B-1).
     *
     * АДРЕС СТРОИТСЯ ЧЕРЕЗ `route()`, А НЕ СКЛЕЙКОЙ. Формат адреса страницы
     * артиста — публичный контракт, и он уже один раз менялся у событий (см.
     * `resolveCanonical()`); копия строки здесь разошлась бы с маршрутом молча.
     *
     * `landing.artist` зарегистрирован в `Seo/routes/web.php`, который
     * подключается из `routes/web.php` вместе с этим маршрутом, поэтому имя
     * доступно на момент ответа. Если маршрут когда-нибудь уедет, `route()`
     * бросит `RouteNotFoundException` на первом же рендере страницы события —
     * это громкий отказ, а не тихо пропавшая ссылка.
     *
     * ПУСТОЙ СПИСОК ДАЁТ ПУСТУЮ СТРОКУ, А НЕ ПУСТОЙ АБЗАЦ: у события без
     * исполнителей (обычное состояние до прогона `artists:sync`) блок не должен
     * появляться вовсе — `<p>` с одним словом «Исполнители» и без ссылок это
     * ровно та «тонкая» разметка, от которой избавляется весь серверный слой.
     *
     * @return string уже экранированный HTML либо пустая строка
     */
    private function artistLinks(Event $event): string
    {
        $links = [];

        foreach ($event->artists as $artist) {
            $links[] = sprintf(
                '<a href="%s">%s</a>',
                htmlspecialchars(
                    route('landing.artist', ['slug' => (string) $artist->slug]),
                    ENT_QUOTES
                ),
                htmlspecialchars((string) $artist->name, ENT_QUOTES)
            );
        }

        if ($links === []) {
            return '';
        }

        return sprintf(
            '<p class="seo-fallback__artists">Исполнители: %s</p>',
            implode(', ', $links)
        );
    }
    /**
     * Строка «когда» для серверного блока.
     *
     * Формулировка нейтральная («Дата и время»), а не «Ближайший сеанс»:
     * `nearestSession()` для прошедшего события возвращает ПОСЛЕДНИЙ сеанс, и
     * «ближайший» на странице состоявшегося концерта было бы прямой неправдой —
     * ровно та рассинхронизация текста и реальности, из-за которой появился P-05.
     */
    private function sessionLine(Event $event, ?object $session): ?string
    {
        $startsAt = $session?->starts_at;

        if ($startsAt === null) {
            return null;
        }

        $line = sprintf('Дата и время: %s', $startsAt->format('d.m.Y, H:i'));

        $total = $event->sessions->count();

        if ($total > 1) {
            $line .= sprintf(' (всего сеансов: %d)', $total);
        }

        return $line;
    }
    /**
     * Строка «где» для серверного блока.
     *
     * Пустые части пропускаются: `address` в `venues` необязателен, и
     * «Площадка: КЗ «Сургут», , » с висящими запятыми выглядит как сбой
     * рендеринга. Название обязательно — без него строки нет вовсе.
     */
    private function venueLine(Event $event, ?object $session): ?string
    {
        $venue = $this->venuePayload($event, $session);

        $name = trim((string) ($venue['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $rest = array_filter([
            trim((string) ($venue['city'] ?? '')),
            trim((string) ($venue['address'] ?? '')),
        ], static fn (string $part): bool => $part !== '');

        return $rest === []
            ? sprintf('Площадка: %s', $name)
            : sprintf('Площадка: %s, %s', $name, implode(', ', $rest));
    }
    /**
     * Лид события для серверного блока.
     *
     * `short_description` побеждает: это текст, написанный для человека.
     * `description` может быть HTML из редактора, поэтому теги снимаются —
     * вставлять чужую разметку внутрь `#app` нельзя, а `v-html` в проекте
     * запрещён для пользовательского текста. Обрезка на 500 символах: это
     * серверный блок для краулера, а не полная страница.
     */
    private function leadText(Event $event): ?string
    {
        foreach ([$event->short_description, $event->description] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $clean = trim(strip_tags($candidate));

            if ($clean !== '') {
                return \Illuminate\Support\Str::limit($clean, 500);
            }
        }

        return null;
    }
    /**
     * Строка цены для серверного блока.
     *
     * Цена берётся из ТОГО ЖЕ `$offer`, что уезжает в JSON-LD, поэтому текст на
     * странице и разметка не могут разойтись. Форматируется через `Money`, а не
     * `number_format` по строке: разделитель разрядов и знак валюты должны
     * совпадать с остальным интерфейсом.
     *
     * @param  array<string, mixed>  $offer
     * @param  bool  $announceSoldOut  печатать ли «Все билеты проданы», когда цены нет
     */
    private function priceLine(array $offer, bool $announceSoldOut): ?string
    {
        $price = $offer['price'] ?? null;

        if (is_string($price) && $price !== '') {
            $currency = is_string($offer['currency'] ?? null) ? $offer['currency'] : 'RUB';

            return sprintf('Билеты от %s', Money::fromDecimal($price, $currency)->format());
        }

        // Цены нет, но билетов и не осталось — это тоже полезное утверждение.
        // `sold_out` у `cancelled` не бывает: там `offerPayload()` отдаёт
        // `cancelled`, поэтому отменённое событие сюда не попадёт.
        return $announceSoldOut && ($offer['availability'] ?? null) === 'sold_out'
            ? 'Все билеты проданы'
            : null;
    }
    /**
     * Описание для meta/og.
     *
     * Отдельным методом, потому что приоритет здесь отличается от приоритета
     * в разметке: `seo_description` (написанный человеком) побеждает всегда,
     * а дальше берётся самое короткое из осмысленных — длинный `description`
     * в meta-теге обрезается поисковиком на ~160 символах.
     *
     * ДЛЯ ПРОШЕДШЕГО/ОТМЕНЁННОГО СОБЫТИЯ описание по умолчанию сообщает статус
     * (аудит P-05/P-17). Сниппет «Билеты на „X“: выбор мест на схеме зала,
     * оплата онлайн» на странице отменённого концерта — это приглашение купить
     * то, чего нет, и самый заметный текст в выдаче.
     */
    public function buildDescription(Event $event, ?object $session = null): string
    {
        if (is_string($event->seo_description) && trim($event->seo_description) !== '') {
            return trim($event->seo_description);
        }

        // Статус важнее `short_description`: тот написан при создании события и
        // после его завершения никем не обновляется, поэтому продолжает
        // приглашать на концерт, который уже прошёл. Явный `seo_description`
        // выше статуса — это осознанное переопределение автором.
        $statusNote = $this->statusNote($event, $session);

        if ($statusNote !== null) {
            return $statusNote;
        }

        if (is_string($event->short_description) && trim($event->short_description) !== '') {
            return trim($event->short_description);
        }

        if (is_string($event->description) && trim($event->description) !== '') {
            // Обрезаем до длины, которую поисковик реально показывает (~160
            // символов), а не до 300 (аудит P-21): сниппет всё равно обрезался,
            // но обрезал его поисковик — по своему счёту символов и не всегда по
            // границе слова. Здесь это делается на границе слова, поэтому текст
            // в выдаче выглядит законченным.
            return \Illuminate\Support\Str::limit(trim(strip_tags($event->description)), 160);
        }

        return sprintf(
            'Билеты на «%s»: выбор мест на схеме зала, оплата онлайн, QR-билет.',
            $event->title
        );
    }
}
