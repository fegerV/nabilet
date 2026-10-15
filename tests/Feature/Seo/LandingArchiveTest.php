<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Tests\Support\CatalogFixtures;
use Tests\TestCase;

/**
 * Архивный блок «прошедшие мероприятия» на посадочных страницах (стратегия SEO,
 * пункт 10 дорожной карты, снимает B-4 частично).
 *
 * ПОЧЕМУ ЭТО ОТДЕЛЬНЫЙ ФАЙЛ. Список посадочной страницы уже показывал прошедшие
 * события (`completed` входит в `publiclyVisible()`, и это осознанно), но делал
 * это вперемешку с предстоящими под одним заголовком «Мероприятия». Для страницы
 * события это ничего не ломало — у неё один предмет. Для страницы артиста или
 * площадки это принципиально: она должна жить годами, и смешение читалось как
 * «афиша, у которой наполовину просрочены даты», а не как архив.
 *
 * ЧТО ИМЕННО ЗАЩИЩАЕТСЯ:
 *
 *   • прошедшее событие НЕ пропадает со страницы — оно переезжает в свой блок
 *     с собственным заголовком. Это обратная сторона порога индексации: если бы
 *     архив выбрасывался, страница артиста с прошедшими концертами отвечала бы
 *     404 и обесценивала уже проиндексированный адрес;
 *   • блок «Прошедшие мероприятия» существует ТОЛЬКО когда есть что показывать,
 *     а «Ближайшие» исчезает, когда предстоящих нет, — страница не обещает
 *     концертов, которых нет;
 *   • событие БЕЗ сеансов не считается прошедшим: у него нет даты, и
 *     утверждать, что оно «прошло», значило бы писать в архив то, что ещё не
 *     состоялось;
 *   • `ItemList` в разметке НЕ меняется: архив остаётся в списке событий
 *     страницы, потому что разметка описывает страницу, а не один её блок.
 *
 * ДАТЫ ПИШУТСЯ ЯВНО (`now()->subMonth()`), А НЕ ЧЕРЕЗ ДЕФОЛТ ФИКСТУРЫ. Дефолт
 * `seedSellableSeat()` — сеанс через неделю, то есть предстоящее событие. Граница
 * «прошедшее/предстоящее» сдвигается вместе с «сейчас», поэтому прошедшую дату
 * невозможно записать константой: она обязана вычисляться от текущего момента.
 */
final class LandingArchiveTest extends TestCase
{
    use CatalogFixtures;
    use RefreshDatabase;

    private const UPCOMING_HEADING = 'Ближайшие мероприятия';
    private const PAST_HEADING = 'Прошедшие мероприятия';
    private const PLAIN_HEADING = 'Мероприятия';

    // ── Страница артиста ───────────────────────────────────────────────────

    /** Ближайшие и прошедшие концерты артиста живут в разных блоках. */
    public function test_an_artist_page_separates_upcoming_from_past(): void
    {
        $soon = $this->seedCatalogEvent('Ближайший концерт', 'zal-arc-1', 'Москва');
        $past = $this->seedCatalogEvent(
            'Прошедший концерт',
            'zal-arc-2',
            'Казань',
            startsAt: now()->subMonth()->toDateTimeString(),
            status: EventStatus::COMPLETED,
        );

        $this->seedArtist('Би-2', 'bi-2', [$soon['event'], $past['event']]);

        $html = $this->artistPage('bi-2');

        $this->assertStringContainsString(self::UPCOMING_HEADING, $html);
        $this->assertStringContainsString(self::PAST_HEADING, $html);
        // `class="events events--archive"` — точная форма: в CSS рендерера
        // `events--archive` встречается как селектор (`.events--archive li.event`),
        // и простое вхождение подстроки ловит CSS, а не разметку.
        $this->assertStringContainsString('class="events events--archive"', $html);

        // Обе ссылки на месте, но каждая В СВОЁМ блоке: предстоящее — ниже
        // заголовка ближайших и ВЫШЕ заголовка архива, прошедшее — ниже архива.
        // Проверка позиций, а не только наличия: список с прошедшим наверху
        // проходил бы проверку «обе строки есть», оставаясь неотсортированным.
        $soonPos = (int) strpos($html, 'Ближайший концерт');
        $pastPos = (int) strpos($html, 'Прошедший концерт');
        $upcomingHeadingPos = (int) strpos($html, self::UPCOMING_HEADING);
        $pastHeadingPos = (int) strpos($html, self::PAST_HEADING);

        $this->assertGreaterThan($upcomingHeadingPos, $soonPos, 'предстоящее не в блоке ближайших');
        $this->assertLessThan($pastHeadingPos, $soonPos, 'предстоящее попало в архив');
        $this->assertGreaterThan($pastHeadingPos, $pastPos, 'прошедшее не в блоке архива');

        $this->assertStringContainsString('href="' . $this->eventUrl($soon) . '"', $html);
        $this->assertStringContainsString('href="' . $this->eventUrl($past) . '"', $html);
    }

    /**
     * Страница только с прошедшими концертами живёт и не обещает лишнего.
     *
     * ЭТО ГЛАВНЫЙ ТЕСТ B-4. Событие живёт два месяца, страница артиста — годами:
     * после последнего концерта она обязана остаться в индексе, но БЕЗ блока
     * «Ближайшие», которого пусто. Пустой заголовок без единой записи — обещание,
     * которого страница не выполняет.
     */
    public function test_a_past_only_page_drops_the_upcoming_block(): void
    {
        $past = $this->seedCatalogEvent(
            'Архивный концерт',
            'zal-arc-3',
            'Орёл',
            startsAt: now()->subYear()->toDateTimeString(),
            status: EventStatus::COMPLETED,
        );

        $this->seedArtist('Аквариум', 'akvarium', [$past['event']]);

        $html = $this->artistPage('akvarium');

        $this->assertStringContainsString(self::PAST_HEADING, $html);
        $this->assertStringNotContainsString(self::UPCOMING_HEADING, $html);
        $this->assertStringContainsString('href="' . $this->eventUrl($past) . '"', $html);
    }

    /** Событие без сеансов — не архив: у него нет даты, значит оно не прошло. */
    public function test_an_event_without_a_session_is_not_classified_as_archive(): void
    {
        $undated = $this->seedCatalogEvent('Без сеанса', 'zal-arc-4', 'Москва');
        $past = $this->seedCatalogEvent(
            'Прошедший концерт',
            'zal-arc-5',
            'Казань',
            startsAt: now()->subMonth()->toDateTimeString(),
            status: EventStatus::COMPLETED,
        );

        $this->seedArtist('Би-2', 'bi-2', [$undated['event'], $past['event']]);

        // Артист — фасет каталога: событие без сеанса остаётся на его странице
        // (см. `CatalogService::eventsAtArtist()`), в отличие от города и
        // площадки, которым сеанс нужен.
        DB::table('sessions')->where('id', $undated['session'])->delete();

        $html = $this->artistPage('bi-2');

        $this->assertStringContainsString(self::PAST_HEADING, $html);

        // Без даты запись попадает в основной блок, а не в архив, и стоит ВЫШЕ
        // заголовка архива. Записать её в «прошедшие» значило бы утверждать, что
        // она состоялась.
        $undatedPos = (int) strpos($html, 'Без сеанса');

        $this->assertGreaterThan(
            (int) strpos($html, self::UPCOMING_HEADING),
            $undatedPos,
            'событие без даты не в основном блоке',
        );
        $this->assertLessThan(
            (int) strpos($html, self::PAST_HEADING),
            $undatedPos,
            'событие без даты попало в архив',
        );
    }

    // ── Страница площадки ──────────────────────────────────────────────────

    /** У площадки архив ведёт себя так же, как у артиста. */
    public function test_a_venue_page_separates_upcoming_from_past(): void
    {
        $soon = $this->seedCatalogEvent('Скоро здесь', 'zal-arc-6', 'Уфа');
        $past = $this->seedCatalogEvent(
            'Было здесь',
            'zal-arc-7',
            'Уфа',
            startsAt: now()->subMonths(2)->toDateTimeString(),
            status: EventStatus::COMPLETED,
        );

        // Фикстура создаёт каждой событию СВОЮ площадку, а тесту нужны два
        // события на ОДНОЙ: сеанс прошедшего переезжает в зал предстоящего.
        // Площадка, где раньше проходили концерты и будут проходить новые, —
        // обычное состояние, которое иначе в тесте не построить.
        DB::table('sessions')->where('event_id', $past['event'])->update(['venue_id' => $soon['venue']]);

        $html = $this->venuePage('zal-arc-6');

        $this->assertStringContainsString(self::UPCOMING_HEADING, $html);
        $this->assertStringContainsString(self::PAST_HEADING, $html);

        $soonPos = (int) strpos($html, 'Скоро здесь');
        $pastPos = (int) strpos($html, 'Было здесь');

        $this->assertLessThan((int) strpos($html, self::PAST_HEADING), $soonPos, 'предстоящее попало в архив');
        $this->assertGreaterThan((int) strpos($html, self::PAST_HEADING), $pastPos, 'прошедшее не в блоке архива');
    }

    // ── Страница без архива ────────────────────────────────────────────────

    /**
     * Живая афиша выглядит как раньше: один список под «Мероприятия».
     *
     * Делить список, когда архива нет, значило бы править страницы, у которых
     * ничего не изменилось: заголовок «Ближайшие» при полном отсутствии архива
     * читался бы как недоделка, а не как структура.
     */
    public function test_a_page_without_an_archive_keeps_a_single_heading(): void
    {
        $this->seedCatalogEvent('Обычный концерт', 'zal-arc-8', 'Тула');

        $html = $this->venuePage('zal-arc-8');

        $this->assertStringContainsString('<h2>' . self::PLAIN_HEADING . '</h2>', $html);
        $this->assertStringNotContainsString('<h2>' . self::UPCOMING_HEADING . '</h2>', $html);
        $this->assertStringNotContainsString('<h2>' . self::PAST_HEADING . '</h2>', $html);
        // Не «events--archive» — этот селектор есть в CSS рендерера, и подстрока
        // ловит таблицу стилей. Ищем конкретно атрибут класса на `<ul>`.
        $this->assertStringNotContainsString('class="events events--archive"', $html);
        // Базовый класс списка должен остаться: одна афиша, без `events--archive`.
        $this->assertStringContainsString('class="events"', $html);
    }

    // ── Подписи и разметка ─────────────────────────────────────────────────

    /** Подпись архивной записи не беднее предстоящей: дата и площадка на месте. */
    public function test_the_archive_keeps_the_date_and_the_venue(): void
    {
        $past = $this->seedCatalogEvent(
            'Прошедший концерт',
            'zal-arc-9',
            'Тула',
            startsAt: now()->subMonths(3)->toDateTimeString(),
            venueName: 'Театр оперы',
            status: EventStatus::COMPLETED,
        );

        $this->seedArtist('Аквариум', 'akvarium', [$past['event']]);

        $html = $this->artistPage('akvarium');

        // Проверяем содержимое БЛОКА архива, а не всей страницы: подписи
        // предстоящих и прошедших обязаны быть одинаково полными. Обеднённая
        // архивная запись (одно название без даты и зала) выглядела бы как
        // битая разметка, а не как осознанный архив.
        $archive = substr($html, (int) strpos($html, self::PAST_HEADING));

        $this->assertStringContainsString('Прошедший концерт', $archive);
        $this->assertStringContainsString('Театр оперы', $archive);
        $this->assertStringContainsString('event-meta', $archive);
    }

    /** Архив остаётся в `ItemList`: разметка описывает страницу целиком. */
    public function test_a_past_event_stays_in_the_item_list_markup(): void
    {
        $past = $this->seedCatalogEvent(
            'Архивный концерт',
            'zal-arc-10',
            'Орёл',
            startsAt: now()->subYear()->toDateTimeString(),
            status: EventStatus::COMPLETED,
        );

        $this->seedArtist('Аквариум', 'akvarium', [$past['event']]);

        $html = $this->artistPage('akvarium');

        $itemList = $this->jsonLdOfType($html, 'ItemList');

        $this->assertNotNull($itemList, 'на странице нет ItemList');
        $this->assertSame($this->eventUrl($past), $itemList['itemListElement'][0]['url']);
    }

    // ── Фикстуры и помощники ───────────────────────────────────────────────

    private function artistPage(string $slug): string
    {
        return (string) $this->get('/artist/' . $slug)->assertOk()->getContent();
    }

    private function venuePage(string $slug): string
    {
        return (string) $this->get('/venue/' . $slug)->assertOk()->getContent();
    }

    private function jsonLdOfType(string $html, string $type): ?array
    {
        preg_match_all(
            '#<script type="application/ld\+json">(.*?)</script>#s',
            $html,
            $matches,
        );

        foreach ($matches[1] as $raw) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded) && ($decoded['@type'] ?? null) === $type) {
                return $decoded;
            }
        }

        return null;
    }
}
