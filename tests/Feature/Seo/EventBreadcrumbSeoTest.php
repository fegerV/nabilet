<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Nabilet\Modules\Events\StateMachines\EventStateMachine;
use Nabilet\Modules\Seo\Services\StructuredDataService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Хлебные крошки страницы мероприятия (SEO-аудит P-09).
 *
 * ДЕФЕКТ. `BreadcrumbList` не генерировался вовсе (grep `Breadcrumb` по `app/`
 * давал ноль совпадений). Google в этом случае показывает в сниппете голый URL —
 * вместе с техническим сегментом `public_id`, который человеку не говорит
 * ничего: `nabilet.ru/event/novyj-god-v-kremle/01JD8Z…`. Хлебные крошки —
 * единственный способ сообщить, что страница лежит ВНУТРИ сайта, и заодно
 * убрать URL из сниппета.
 *
 * ЧТО ИМЕННО ПРОВЕРЯЕТСЯ. Не «тег присутствует», а свойства, за которые этот
 * тег и существует:
 *
 *   - первая позиция — корень сайта, и он РЕАЛЬНО отвечает 200 (Google обходит
 *     адреса из `item` и битые показывает в Search Console как «URL not found»);
 *   - последняя позиция — канонический адрес страницы, то есть цепочка
 *     заканчивается там, где заканчивается документ;
 *   - `position` идёт подряд от 1 без дыр — иначе Google отбрасывает разметку
 *     целиком, а не «пропускает непонятный элемент».
 *
 * ОТДЕЛЬНО ПРОВЕРЯЕТСЯ ТО, ЧЕГО В РАЗМЕТКЕ БЫТЬ НЕ ДОЛЖНО. `performer` не
 * выдумывается при отсутствии данных: подставить туда организацию значило бы
 * объявить устроителя исполнителем. Тест фиксирует это как решение, а не как
 * случайность, — чтобы «доработка полноты карточки» не вернула дефект.
 *
 * УРОВЕНЬ КАТЕГОРИИ (аудит P-12). Он появился вместе с посадочной страницей
 * `/category/{slug}` и выводится ТОЛЬКО когда адрес этой страницы заведомо
 * отвечает 200. Поэтому здесь три отдельных теста на три случая, когда уровня
 * быть не должно: событие непублично, категория снята с публикации, категории
 * нет вовсе. Все три — не «крайние случаи», а ровно те состояния, в которых
 * ссылка в разметке оказалась бы битой, а битая ссылка в `BreadcrumbList`
 * обесценивает разметку страницы целиком.
 */
final class EventBreadcrumbSeoTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    // ── BreadcrumbList на странице ─────────────────────────────────────────

    /** Разметка крошек присутствует и разбирается как JSON. */
    public function test_the_event_page_carries_a_breadcrumb_list(): void
    {
        $breadcrumb = $this->breadcrumb($this->pageResponse($this->seedEvent()));

        $this->assertSame('https://schema.org', $breadcrumb['@context']);
        $this->assertSame('BreadcrumbList', $breadcrumb['@type']);
        $this->assertNotEmpty($breadcrumb['itemListElement']);
    }

    /**
     * Первая позиция — корень сайта, и он отвечает 200.
     *
     * ПРОВЕРКА ЖИВУЧЕСТИ ССЫЛКИ ЗДЕСЬ НЕ ФОРМАЛЬНОСТЬ. Крошка — это ссылка,
     * которую поисковик обходит. Если посадочной страницы категории нет (аудит
     * P-12), поставить её в цепочку нельзя: битый `item` обесценивает разметку
     * сильнее, чем короткая цепочка. Тест фиксирует, что каждый `item`, который
     * мы всё-таки выводим, действительно разрешается.
     */
    public function test_the_first_crumb_is_the_site_root_and_it_is_reachable(): void
    {
        $crumbs = $this->items($this->breadcrumb($this->pageResponse($this->seedEvent())));

        // `url('/')` — как и в двух других сборщиках крошек. Здесь была своя
        // склейка `rtrim(config('app.url'),'/') . '/'`, то есть тест повторял
        // реализацию контроллера и заодно фиксировал завершающий слэш, которого
        // у `url('/')` нет.
        $home = url('/');

        $this->assertSame(1, $crumbs[0]['position']);
        $this->assertSame('Главная', $crumbs[0]['name']);
        $this->assertSame($home, $crumbs[0]['item']);

        // Корень обслуживается web-роутом `/`: 200, когда витрина собрана.
        // 503 (витрина не собрана) тоже означает «адрес существует», но
        // сломанную сборку этот тест ловить не должен.
        $this->assertContains(
            $this->get('/')->getStatusCode(),
            [200, 503],
            'корень сайта из крошек не отвечает'
        );
    }

    /** Последняя позиция — канонический адрес самой страницы. */
    public function test_the_last_crumb_is_the_canonical_url_of_the_page(): void
    {
        $event = $this->seedEvent();
        $response = $this->pageResponse($event);
        $crumbs = $this->items($this->breadcrumb($response));

        $last = $crumbs[array_key_last($crumbs)];

        $this->assertSame('Тестовое событие', $last['name']);
        $this->assertSame(
            route('events.show', ['slug' => $event['slug'], 'publicId' => $event['publicId']]),
            $last['item']
        );
    }

    /**
     * `position` идёт подряд от 1.
     *
     * Требование Google: позиции должны быть уникальны и начинаться с 1.
     * Разрыв (например, 1 и 3 после отбрасывания пустого уровня) — это ошибка,
     * из-за которой блок не показывается вообще.
     */
    public function test_the_positions_are_sequential(): void
    {
        $crumbs = $this->items($this->breadcrumb($this->pageResponse($this->seedEvent())));

        $this->assertSame(
            range(1, count($crumbs)),
            array_column($crumbs, 'position'),
            'position крошек идёт с дырами — Google отбрасывает такую разметку'
        );
    }

    /**
     * Крошки есть и у прошедшего, и у отменённого события.
     *
     * Эти страницы остаются доступными по прямой ссылке (аудит P-01: архивация и
     * отмена — не повод для 404), и в выдачу они тоже попадают. Лишать их
     * навигационного сигнала не за что.
     *
     * ДВА ОТДЕЛЬНЫХ ТЕСТА, А НЕ ЦИКЛ В ОДНОМ. `EventRepository::findBySlug()`
     * разрешает организацию как «первую по id», поэтому во втором проходе цикла
     * событие принадлежало бы уже ДРУГОЙ организации и страница честно отдавала
     * 404. Это ровно дефект P-03 (публичный URL не содержит организацию), и
     * обходить его в тесте подменой `organization_id` значило бы прятать симптом
     * в месте, где он заметен.
     */
    public function test_a_completed_event_keeps_its_breadcrumbs(): void
    {
        $crumbs = $this->items(
            $this->breadcrumb($this->pageResponse($this->seedEvent(EventStateMachine::COMPLETED)))
        );

        $this->assertCount(2, $crumbs);
        $this->assertSame('Главная', $crumbs[0]['name']);
        $this->assertSame('Тестовое событие', $crumbs[1]['name']);
    }

    /** Отменённое событие остаётся навигируемым. */
    public function test_a_cancelled_event_keeps_its_breadcrumbs(): void
    {
        $crumbs = $this->items(
            $this->breadcrumb($this->pageResponse($this->seedEvent(EventStateMachine::CANCELLED)))
        );

        $this->assertCount(2, $crumbs);
        $this->assertSame('Главная', $crumbs[0]['name']);
        $this->assertSame('Тестовое событие', $crumbs[1]['name']);
    }

    // ── Уровень категории (аудит P-12) ─────────────────────────────────────

    /**
     * Событие с активной категорией получает трёхуровневую цепочку.
     *
     * Ссылка на категорию не просто присутствует — она ПРОВЕРЯЕТСЯ запросом:
     * Google обходит `item` крошек, и единственное доказательство, что ссылка
     * уместна, это ответ 200 на неё. Проверка на «строка похожа на адрес» здесь
     * была бы бесполезна.
     */
    public function test_the_category_level_is_added_for_an_active_category(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $event = $this->seedEvent(EventStateMachine::PUBLISHED, $category);

        $crumbs = $this->items($this->breadcrumb($this->pageResponse($event)));

        $this->assertCount(3, $crumbs);
        $this->assertSame('Главная', $crumbs[0]['name']);
        $this->assertSame('Концерты', $crumbs[1]['name']);
        $this->assertSame('Тестовое событие', $crumbs[2]['name']);
        $this->assertSame([1, 2, 3], array_column($crumbs, 'position'));

        $categoryUrl = route('landing.category', ['slug' => 'koncerty']);
        $this->assertSame($categoryUrl, $crumbs[1]['item']);

        // Адрес из крошки обязан отвечать: иначе уровень не следовало выводить.
        $this->get($categoryUrl)->assertStatus(200);
    }

    /**
     * Непубличное событие уровня категории не получает.
     *
     * Страница `scheduled`/`cancelled` доступна по прямой ссылке, но помечена
     * `noindex`, а её категория может не иметь ни одного публичного события —
     * тогда `/category/{slug}` отвечает 404. Короткая цепочка валидна; битая
     * ссылка в разметке — нет.
     */
    public function test_the_category_level_is_skipped_for_a_non_public_event(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $event = $this->seedEvent(EventStateMachine::CANCELLED, $category);

        $crumbs = $this->items($this->breadcrumb($this->pageResponse($event)));

        $this->assertCount(2, $crumbs);
        $this->assertSame(['Главная', 'Тестовое событие'], array_column($crumbs, 'name'));

        // Обратная сторона условия: страницы категории действительно нет.
        $this->get(route('landing.category', ['slug' => 'koncerty']))->assertStatus(404);
    }

    /** Категория, снятая с публикации, уровня не получает. */
    public function test_the_category_level_is_skipped_for_an_inactive_category(): void
    {
        $category = $this->seedCategory('Закрытая', 'zakrytaya', 'inactive');
        $event = $this->seedEvent(EventStateMachine::PUBLISHED, $category);

        $crumbs = $this->items($this->breadcrumb($this->pageResponse($event)));

        $this->assertCount(2, $crumbs);
        $this->assertSame(['Главная', 'Тестовое событие'], array_column($crumbs, 'name'));

        $this->get(route('landing.category', ['slug' => 'zakrytaya']))->assertStatus(404);
    }

    /** Событие без категории остаётся двухуровневым — как было до P-12. */
    public function test_the_category_level_is_skipped_when_the_event_has_no_category(): void
    {
        $crumbs = $this->items($this->breadcrumb($this->pageResponse($this->seedEvent())));

        $this->assertCount(2, $crumbs);
        $this->assertSame(['Главная', 'Тестовое событие'], array_column($crumbs, 'name'));
    }

    /**
     * Блок Event никуда не делся: крошки — ВТОРОЙ документ JSON-LD, а не замена.
     *
     * Ошибка, которую этот тест закрывает: соблазн «объединить в один `@graph`»
     * или переписать первый `<script>`. Тогда страница теряет Event и вместе с
     * ним все rich-результаты, а крошки остаются — то есть падение выглядит как
     * успех.
     */
    public function test_the_event_block_is_still_present_alongside_the_breadcrumbs(): void
    {
        $blocks = $this->jsonLdBlocks($this->pageResponse($this->seedEvent()));

        $this->assertCount(2, $blocks, 'ожидались два блока JSON-LD: Event и BreadcrumbList');

        $types = array_column($blocks, '@type');
        $this->assertContains('Event', $types);
        $this->assertContains('BreadcrumbList', $types);
    }

    // ── Генератор ──────────────────────────────────────────────────────────

    /**
     * Пустые имена уровней пропускаются, позиции перенумеровываются.
     *
     * Так выглядит будущее добавление уровня категории (P-12): у события без
     * категории `name` окажется пустой строкой. Наивная реализация оставила бы
     * `position` по индексу исходного массива — и цепочка «1, 3» стала бы
     * невалидной ровно у тех событий, у которых категории нет.
     */
    public function test_empty_levels_are_skipped_without_breaking_the_numbering(): void
    {
        $list = app(StructuredDataService::class)->generateBreadcrumbList([
            ['name' => 'Главная', 'url' => 'https://nabilet.ru/'],
            ['name' => '', 'url' => 'https://nabilet.ru/category/'],
            ['name' => 'Новогодний концерт', 'url' => 'https://nabilet.ru/event/x/1'],
        ]);

        $crumbs = $list['itemListElement'];

        $this->assertCount(2, $crumbs);
        $this->assertSame([1, 2], array_column($crumbs, 'position'));
        $this->assertSame('Новогодний концерт', $crumbs[1]['name']);
    }

    /**
     * Уровень без URL остаётся без ключа `item`, а не с пустой строкой.
     *
     * `"item": ""` — это не «нет ссылки», а ссылка на текущий документ:
     * парсер попытается её разрешить. Отсутствие ключа — единственный способ
     * сказать «уровень без адреса».
     */
    public function test_a_level_without_a_url_has_no_item_key(): void
    {
        $list = app(StructuredDataService::class)->generateBreadcrumbList([
            ['name' => 'Главная', 'url' => 'https://nabilet.ru/'],
            ['name' => 'Новогодний концерт'],
        ]);

        $this->assertArrayHasKey('item', $list['itemListElement'][0]);
        $this->assertArrayNotHasKey('item', $list['itemListElement'][1]);
    }

    // ── Чего в разметке быть не должно ─────────────────────────────────────

    /**
     * `performer` не выдумывается: колонки с артистом в схеме нет.
     *
     * Аудит P-09 отмечает `performer` как незаполненный. Заполнить его нечем:
     * в `events` нет соответствующего поля, а подставить организацию нельзя —
     * `organizer` уже занят ею, и Google увидит событие, у которого устроитель и
     * исполнитель — одно юрлицо. `performer` в schema.org опционален; отсутствие
     * ключа ошибкой не является, а противоречивое значение — является.
     */
    public function test_no_performer_is_fabricated_when_there_is_no_data(): void
    {
        $jsonLd = $this->jsonLdBlocks($this->pageResponse($this->seedEvent()));

        $event = null;

        foreach ($jsonLd as $block) {
            if (($block['@type'] ?? null) === 'Event') {
                $event = $block;
            }
        }

        $this->assertNotNull($event, 'блок Event пропал');
        $this->assertArrayNotHasKey('performer', $event);
    }

    // ── Фикстуры ───────────────────────────────────────────────────────────

    /**
     * @return array{org: int, event: int, session: int, slug: string, publicId: string, inventory: int}
     */
    private function seedEvent(string $status = EventStateMachine::PUBLISHED, ?int $categoryId = null): array
    {
        [$orgId, $eventId, $sessionId, $inventoryId] = $this->seedSellableSeat(3);

        $values = ['status' => $status];

        if ($categoryId !== null) {
            $values['category_id'] = $categoryId;
        }

        DB::table('events')->where('id', $eventId)->update($values);

        $row = DB::table('events')->where('id', $eventId)->first();

        return [
            'org' => $orgId,
            'event' => $eventId,
            'session' => $sessionId,
            'slug' => (string) $row->slug,
            'publicId' => (string) $row->public_id,
            'inventory' => $inventoryId,
        ];
    }

    /**
     * Категория каталога.
     *
     * `public_id` — ULID в base32: колонка NOT NULL и UNIQUE, пустой её оставить
     * нельзя. Временных меток у таблицы нет (`$timestamps = false`).
     */
    private function seedCategory(string $name, string $slug, string $status = 'active'): int
    {
        return (int) DB::table('event_categories')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => $slug,
            'status' => $status,
        ]);
    }

    /** @param array{slug: string, publicId: string} $event */
    private function pageResponse(array $event): TestResponse
    {
        $response = $this->get(route('events.show', [
            'slug' => $event['slug'],
            'publicId' => $event['publicId'],
        ]));

        $response->assertStatus(200);

        return $response;
    }

    /**
     * Все блоки JSON-LD страницы, в порядке появления.
     *
     * В отличие от хелпера в `EventSeoPageTest` (первое совпадение), здесь нужны
     * ВСЕ: смысл проверки — в их количестве и типах.
     *
     * @return list<array<string, mixed>>
     */
    private function jsonLdBlocks(TestResponse $response): array
    {
        $html = (string) $response->getContent();

        preg_match_all(
            '/<script type="application\/ld\+json">(.*?)<\/script>/s',
            $html,
            $matches
        );

        $this->assertNotEmpty($matches[1], 'в HTML нет ни одного блока JSON-LD');

        $blocks = [];

        foreach ($matches[1] as $json) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $blocks[] = $decoded;
        }

        return $blocks;
    }

    /**
     * Блок BreadcrumbList страницы.
     *
     * @return array<string, mixed>
     */
    private function breadcrumb(TestResponse $response): array
    {
        foreach ($this->jsonLdBlocks($response) as $block) {
            if (($block['@type'] ?? null) === 'BreadcrumbList') {
                return $block;
            }
        }

        $this->fail('на странице нет блока BreadcrumbList');
    }

    /**
     * @param  array<string, mixed>  $breadcrumb
     * @return list<array<string, mixed>>
     */
    private function items(array $breadcrumb): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $breadcrumb['itemListElement'];

        return $items;
    }
}
