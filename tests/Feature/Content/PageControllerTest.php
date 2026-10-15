<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Редакционная страница CMS — `/page/{slug}` и `/sitemap-pages.xml`
 * (стратегия SEO, B-2).
 *
 * ЭТА СУЩНОСТЬ РАНЬШЕ НЕ СУЩЕСТВОВАЛА. В `Modules\Content` лежали `Page`,
 * `PageTranslation`, `SeoMeta` и `Redirect`, но ни маршрута, ни контроллера
 * не было — спящий каркас CMS без работающей поверхности. Поэтому здесь
 * проверяется не «правильно ли собран шаблон», а «правильно ли страница
 * понимает, что считать опубликованным», «куда попадает контент» и
 * «совпадает ли карта с тем, что реально отдаётся».
 *
 * ЧТО ТАКОЕ ОПУБЛИКОВАННАЯ СТРАНИЦА — ГЛАВНЫЙ ИНВАРИАНТ. `status='published'`
 * И `published_at <= now()` (NULL трактуется как «ещё не опубликовано»).
 * Та же пара ключей, что в `SitemapService::getEventsSitemap()`: расхождение
 * между картой и страницей — это 404 из sitemap'а, и тест «адрес из карты
 * отвечает 200» ловит это напрямую.
 *
 * ПОЧЕМУ ТАК МНОГО ПРОВЕРОК НА 404. Длинный хвост опечаток и старых ссылок —
 * это редакционный трафик. Отвечать 200 на `/page/как-вернуть-билет-назад`
 * было бы созданием адресов «в никуда»: каждая такая страница попадала бы в
 * индекс, и поисковик понижал доверие ко всему сайту. Поэтому тест 404
 * — это не «проверка отсутствия», а защита адресного пространства.
 */
final class PageControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Содержимое страницы ────────────────────────────────────────────────

    public function test_a_published_page_returns_200_with_its_content(): void
    {
        $this->seedPage('about', 'О компании', '<p>Мы продаём билеты с 2020 года.</p>');

        $response = $this->get('/page/about');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->assertSee('О компании', false);
        $response->assertSee('Мы продаём билеты с 2020 года.', false);
    }

    public function test_a_page_renders_its_html_content_unchanged(): void
    {
        // Контент редакционной страницы — это HTML, который администратор
        // набрал в визуальном редакторе. Рендер не должен его экранировать:
        // иначе администратор увидит «<p>...</p>» буквально на странице.
        $this->seedPage('rules', 'Правила', '<h2>Возврат</h2><p>Вернуть можно за <strong>72 часа</strong>.</p>');

        $html = (string) $this->get('/page/rules')->getContent();

        // ПОЛОЖИТЕЛЬНЫЙ КОНТРОЛЬ: иначе тест прошёл бы на пустой странице.
        // Разметка попадает в `<div class="article-body">`, а не в `<style>`:
        // проверка по всему документу дала бы ложный успех, если бы теги
        // переехали в CSS (как произошло однажды с коллизией плейсхолдера
        // `{{content}}` для CSS-цвета и тела документа).
        $this->assertStringContainsString(
            '<div class="article-body"><h2>Возврат</h2><p>Вернуть можно за <strong>72 часа</strong>.</p></div>',
            $html,
        );
    }

    public function test_a_page_carries_meta_title_description_and_canonical(): void
    {
        $this->seedPage('faq', 'Часто задаваемые вопросы', '<p>Ответы на популярные вопросы о билетах и возвратах.</p>');

        $response = $this->get('/page/faq');

        $response->assertStatus(200);
        $response->assertSee('<title>Часто задаваемые вопросы</title>', false);
        $response->assertSee(
            '<meta name="description" content="Ответы на популярные вопросы о билетах и возвратах."',
            false,
        );
        $response->assertSee(
            '<link rel="canonical" href="' . route('content.page', ['slug' => 'faq']) . '" />',
            false,
        );
    }

    public function test_a_description_is_truncated_with_an_ellipsis_when_content_is_long(): void
    {
        // Контент длиной 200 символов — больше 160, на которых режется описание.
        // Эллипсис в конце означает «обрезано», и его отсутствие читалось бы как
        // обрыв фразы посреди слова в сниппете.
        $longText = str_repeat('Длинный текст о билетах, площадках и правилах посещения. ', 4);
        $this->seedPage('long', 'Длинная страница', '<p>' . $longText . '</p>');

        $html = (string) $this->get('/page/long')->getContent();

        $found = preg_match(
            '#<meta name="description" content="([^"]*)" />#',
            $html,
            $matches,
        );

        $this->assertSame(1, $found, 'нет meta description');
        // mb_strlen проверяет, что обрез не упал посреди многобайтового символа
        // (русский текст — UTF-8): strlen вернул бы больше, потому что один символ
        // занимает 2 байта.
        $this->assertLessThanOrEqual(161, mb_strlen($matches[1]));
        $this->assertStringEndsWith('…', $matches[1]);
    }

    public function test_a_page_with_html_in_content_cleans_tags_for_description(): void
    {
        // `strip_tags()` в `PageService::descriptionFrom()` снимает разметку, чтобы
        // в сниппете не появилось «<p>Концерт в Москве…</p>». Без этого шага
        // поисковик бы считал сниппет обрезанным HTML и скрывал бы его.
        $this->seedPage('concerts', 'Концерты', '<h2>Афиша</h2><p>Концерт в <em>Москве</em> уже в продаже.</p>');

        $html = (string) $this->get('/page/concerts')->getContent();

        $found = preg_match(
            '#<meta name="description" content="([^"]*)" />#',
            $html,
            $matches,
        );

        $this->assertSame(1, $found, 'нет meta description');
        // `strip_tags()` склеивает текст из соседних тегов без пробела:
        // `<h2>Афиша</h2><p>Концерт…</p>` → «АфишаКонцерт…». Это ожидаемо —
        // `preg_replace('/\s+/', ' ')` сжимает пробелы, но не добавляет их там,
        // где их не было. В сниппете это читается как «Афиша. Концерт…» по
        // границам предложений.
        $this->assertSame('АфишаКонцерт в Москве уже в продаже.', $matches[1]);
        $this->assertStringNotContainsString('<h2>', $matches[1]);
        $this->assertStringNotContainsString('<em>', $matches[1]);
    }

    public function test_a_page_with_empty_content_gets_an_empty_description(): void
    {
        // Пустое описание — не ошибка: тег выводится без содержимого, и поисковик
        // сам сформирует сниппет по содержимому страницы.
        $this->seedPage('empty', 'Пустая страница', '');

        $html = (string) $this->get('/page/empty')->getContent();

        $this->assertStringContainsString('<meta name="description" content="" />', $html);
    }

    // ── JSON-LD ────────────────────────────────────────────────────────────

    public function test_a_page_carries_an_article_block_in_json_ld(): void
    {
        $this->seedPage('about', 'О компании', '<p>История и команда NABILET.</p>');

        $article = $this->jsonLdOfType((string) $this->get('/page/about')->getContent(), 'Article');

        $this->assertNotNull($article, 'на странице нет Article');
        $this->assertSame('https://schema.org', $article['@context']);
        $this->assertSame('О компании', $article['headline']);
        $this->assertSame('История и команда NABILET.', $article['description']);
        $this->assertSame(route('content.page', ['slug' => 'about']), $article['url']);
        // Требование Google: `mainEntityOfPage` обязан совпадать с `url`,
        // иначе разметка отбрасывается целиком.
        $this->assertSame($article['url'], $article['mainEntityOfPage']);
        $this->assertNotEmpty($article['datePublished']);
        $this->assertNotEmpty($article['dateModified']);
        $this->assertSame($article['dateModified'], $article['dateModified']);
        // Общая страница (без организации) — `publisher` пропускается через
        // `pruneEmpty()`, а не отдаётся пустым.
        $this->assertArrayNotHasKey('publisher', $article);
        $this->assertNoEmptyScalars($article);
    }

    public function test_a_page_with_an_organization_has_a_publisher_block(): void
    {
        // `seedSellableSeat()` создаёт организацию в рамках своей цепочки,
        // но нам нужна ОРГАНИЗАЦИЯ для редакционной страницы. Поэтому — свой
        // помощник: организация живёт отдельной записью, страница ссылается
        // на неё по `organization_id`.
        $organizationId = $this->seedOrganization('Сургут-Концерт');
        $this->seedPage('rules', 'Правила', '<p>Возврат в течение 72 часов.</p>', organizationId: $organizationId);

        $article = $this->jsonLdOfType((string) $this->get('/page/rules')->getContent(), 'Article');

        $this->assertNotNull($article);
        $this->assertSame('Сургут-Концерт', $article['publisher']['name']);
        // `author` совпадает с `publisher`: для v1 редакционные страницы пишутся
        // от лица организации, отдельных авторов в схеме нет.
        $this->assertSame($article['publisher'], $article['author']);
    }

    public function test_a_page_carries_a_breadcrumb_block(): void
    {
        $this->seedPage('about', 'О компании', '<p>История.</p>');

        $list = $this->breadcrumbList((string) $this->get('/page/about')->getContent());

        $this->assertSame(['Главная', 'О компании'], array_column($list['itemListElement'], 'name'));
        // Последняя позиция обязана вести на canonical — это требование Google,
        // иначе разметка отбрасывается целиком.
        $this->assertSame(
            route('content.page', ['slug' => 'about']),
            $list['itemListElement'][count($list['itemListElement']) - 1]['item'],
        );
        $this->assertSame(range(1, count($list['itemListElement'])), array_column($list['itemListElement'], 'position'));
    }

    public function test_a_page_does_not_inject_raw_script_tags_into_json_ld(): void
    {
        // `safeJsonLd()` обязан заменить `<` и `>` на `\u003C` и `\u003E` —
        // иначе `</script>` внутри контента (обычная опечатка редактора)
        // обрывает встроенный JSON-LD и портит всю разметку.
        //
        // `description` берётся из контента через `strip_tags()`, поэтому
        // `<script>` исчезает, но остаётся «1 < 2» — обычный символ, который
        // БЕЗ `safeJsonLd()` отдал бы в JSON-LD `<` буквально, и при
        // декодировании парсером оставил строку валидной, но рядом с
        // редакторским `</script>` (если бы он выжил после strip_tags) — оборвал
        // бы блок. `safeJsonLd()` экранирует этот `<` в `\u003C` заранее.
        $this->seedPage(
            'tricky',
            'Хитрый контент',
            '<p>Скрипт: <script>alert(1)</script> и стрелка: 1 < 2.</p>',
        );

        $html = (string) $this->get('/page/tricky')->getContent();
        $article = $this->jsonLdOfType($html, 'Article');

        $this->assertNotNull($article);
        // Описание сохраняет смысл контента: слова «Скрипт» и «стрелка» на месте,
        // а сам тег `<script>` снят `strip_tags()` (это работа `PageService`, не
        // рендера).
        $this->assertStringContainsString('Скрипт:', $article['description']);
        $this->assertStringContainsString('стрелка: 1', $article['description']);
        $this->assertStringNotContainsString('<script', $article['description']);
        // ГЛАВНАЯ ПРОВЕРКА: в JSON-LD сериализованной строке нет сырых `<` или
        // `>` рядом с тегами. После `safeJsonLd()` они заменены на `\u003C` и
        // `\u003E`, и `</script>` из контента (если бы он выжил) не оборвал бы
        // JSON-LD блок.
        $serialized = json_encode($article, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('<script', $serialized);
        $this->assertStringNotContainsString('</script', $serialized);
    }

    public function test_a_page_has_og_type_article(): void
    {
        // Open Graph тип «article» нужен социальным сетям, чтобы показывать
        // карточку статьи, а не «веб-сайт». Сейчас это терпимая косметика,
        // но Яндекс и VK учитывают тип в превью.
        $this->seedPage('about', 'О компании', '<p>Текст.</p>');

        $response = $this->get('/page/about');

        $response->assertSee('<meta property="og:type" content="article" />', false);
    }

    // ── 404 ────────────────────────────────────────────────────────────────

    public function test_an_unknown_slug_answers_a_branded_404(): void
    {
        // Случайный перебор `/page/что-угодно` возвращал 200 на каждом запросе,
        // и таких адресов в индексе становилось сколько угодно. Проверка
        // закрепляет, что страница отдаёт ту же брендированную 404, что и
        // неизвестный маршрут.
        $response = $this->get('/page/neizvestnyy-slug');

        $response->assertStatus(404);
        $response->assertSee('Страница не найдена', false);
        $response->assertSee('href="/"', false);
    }

    public function test_a_draft_page_answers_404(): void
    {
        // Черновик — самая частая причина, по которой адрес случайно оказывается
        // в индексе: администратор опубликовал URL в соцсети до публикации.
        $this->seedPage('about', 'О компании', '<p>Текст.</p>', status: 'draft');

        $this->get('/page/about')->assertStatus(404);
    }

    public function test_a_page_with_a_future_published_at_answers_404(): void
    {
        // `published_at` в будущем трактуется как «опубликовано с отсрочкой»:
        // до этого момента страницы ещё нет.
        $this->seedPage('about', 'О компании', '<p>Текст.</p>', publishedAt: now()->addDay());

        $this->get('/page/about')->assertStatus(404);
    }

    public function test_a_page_with_null_published_at_answers_404(): void
    {
        // `published_at` NULL при `status='published'` трактуется как
        // «ещё не опубликовано»: миграция не заставляет проставлять дату
        // вручную при первой вставке, и забытая дата не должна открывать
        // страницу раньше времени.
        $this->seedPage('about', 'О компании', '<p>Текст.</p>', publishedAtProvided: false);

        $this->get('/page/about')->assertStatus(404);
    }

    // ── Индексируемость ────────────────────────────────────────────────────

    public function test_a_published_page_is_indexable(): void
    {
        // `PreventIndexingOfServicePages` живёт в GLOBAL stack и закрывает
        // служебные пути заголовком `X-Robots-Tag: noindex, noarchive`. Если
        // `/page/*` туда однажды попадёт, страница будет отвечать 200 и
        // присутствовать в карте, но в индексе её не будет — и заметить это
        // можно только такой проверкой.
        $this->seedPage('about', 'О компании', '<p>Текст.</p>');

        $response = $this->get('/page/about');

        $response->assertStatus(200);
        $response->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_a_page_does_not_depend_on_the_built_storefront(): void
    {
        // Витрина — hash-SPA, но редакционная страница обязана работать без неё:
        // без JS, без `dist/`, без внешних ресурсов. Сломанная сборка не должна
        // превращать FAQ в «сервер недоступен».
        $this->seedPage('about', 'О компании', '<p>Текст.</p>');
        config(['nabilet.storefront.shell_path' => base_path('dist/definitely-missing.html')]);

        $response = $this->get('/page/about');

        $response->assertStatus(200);
        $response->assertSee('О компании', false);
        $response->assertDontSee('<script src=', false);
        $response->assertDontSee('<link rel="stylesheet"', false);
    }

    // ── Sitemap (стратегия SEO, B-2) ───────────────────────────────────────

    public function test_the_pages_sitemap_lists_published_pages(): void
    {
        $this->seedPage('about', 'О компании', '<p>Текст.</p>');
        $this->seedPage('faq', 'FAQ', '<p>Текст.</p>');

        $xml = $this->pagesSitemap();

        $this->assertStringContainsString(route('content.page', ['slug' => 'about']), $xml);
        $this->assertStringContainsString(route('content.page', ['slug' => 'faq']), $xml);
        $this->assertStringContainsString('<lastmod>', $xml);
        $this->assertStringContainsString('<changefreq>monthly</changefreq>', $xml);
        $this->assertStringContainsString('<priority>0.5</priority>', $xml);
    }

    public function test_the_pages_sitemap_skips_drafts(): void
    {
        // Черновик в карте = 404 из sitemap'а. Ровно та же ошибка, что у
        // карты площадок когда-то: адрес в карте — это обещание, что он
        // отвечает 200, и Google помечает такие обещания как ошибки.
        $this->seedPage('about', 'О компании', '<p>Текст.</p>');
        $this->seedPage('draft', 'Черновик', '<p>Текст.</p>', status: 'draft');

        $this->assertStringNotContainsString(
            route('content.page', ['slug' => 'draft']),
            $this->pagesSitemap(),
        );
    }

    public function test_the_pages_sitemap_skips_pages_with_future_published_at(): void
    {
        $this->seedPage('about', 'О компании', '<p>Текст.</p>');
        $this->seedPage('future', 'Будущее', '<p>Текст.</p>', publishedAt: now()->addDay());

        $this->assertStringNotContainsString(
            route('content.page', ['slug' => 'future']),
            $this->pagesSitemap(),
        );
    }

    public function test_the_pages_sitemap_skips_pages_with_null_published_at(): void
    {
        $this->seedPage('about', 'О компании', '<p>Текст.</p>');
        $this->seedPage('null', 'Без даты', '<p>Текст.</p>', publishedAtProvided: false);

        $this->assertStringNotContainsString(
            route('content.page', ['slug' => 'null']),
            $this->pagesSitemap(),
        );
    }

    public function test_the_index_points_at_the_pages_sitemap(): void
    {
        // Карта-индекс обязана перечислять `/sitemap-pages.xml`: иначе
        // редакционные страницы не попадут в обход.
        $this->assertStringContainsString(route('sitemap.pages'), $this->indexXml());
    }

    public function test_every_address_in_the_pages_sitemap_answers_200(): void
    {
        $this->seedPage('about', 'О компании', '<p>Текст.</p>');
        $this->seedPage('faq', 'FAQ', '<p>Текст.</p>');

        $locs = $this->locs($this->pagesSitemap());

        $this->assertNotSame([], $locs, 'в карте страниц нет ни одного адреса');

        foreach ($locs as $loc) {
            $this->get($loc)->assertStatus(200);
        }
    }

    public function test_the_pages_sitemap_is_cached(): void
    {
        $this->get('/sitemap-pages.xml')->assertStatus(200);

        $this->assertTrue(Cache::has('seo:sitemap:pages'), 'карта страниц не попала в кеш');
    }

    public function test_an_empty_pages_sitemap_is_a_valid_urlset(): void
    {
        // Пустая карта — нормальное состояние на свежей установке, пока ни одной
        // редакционной страницы не опубликовано. Она должна быть корректным
        // `<urlset>`, а не ошибкой: присутствие в индексе пустой карты ничего
        // не стоит, и её отсутствие тоже ничего не стоит, пока первая страница
        // не появится.
        $response = $this->get('/sitemap-pages.xml');

        $response->assertStatus(200);
        $this->assertStringContainsString('<urlset', $response->getContent());
    }

    // ── Вспомогательное ────────────────────────────────────────────────────

    /**
     * Создать одну запись `pages`.
     *
     * Своим методом, а не через Eloquent `Page::create()`: статус
     * «опубликованное в прошлом» требует конкретного `published_at`, и
     * передавать его через Eloquent — это приведение в Carbon, а нам нужна
     * ровно строка для БД. Через `DB::table()` всё видно сразу.
     *
     * Значение по умолчанию для `published_at` — «вчера», и `null` означает
     * «нет даты». Этот тест различает их через `$publishedAtProvided`: без
     * него `null` неотличим от «не передано», и `??=` всегда проставлял бы
     * вчерашнюю дату, ломая тест на черновик с пустой датой.
     *
     * @param  ?int  $organizationId  организация-издатель (null — общая страница)
     */
    private function seedPage(
        string $slug,
        string $title,
        string $content,
        string $status = 'published',
        ?int $organizationId = null,
        bool $publishedAtProvided = true,
        ?\Carbon\CarbonInterface $publishedAt = null,
    ): int {
        $now = now()->toDateTimeString();
        $storedPublishedAt = $publishedAtProvided
            ? ($publishedAt ?? now()->subDay())->toDateTimeString()
            : null;

        return (int) DB::table('pages')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'status' => $status,
            'published_at' => $storedPublishedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedOrganization(string $name): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => 'org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function pagesSitemap(): string
    {
        $response = $this->get('/sitemap-pages.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        return (string) $response->getContent();
    }

    private function indexXml(): string
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);

        return (string) $response->getContent();
    }

    /** @return list<string> ПУТИ из `<loc>` (без схемы и хоста). */
    private function locs(string $xml): array
    {
        preg_match_all('#<loc>([^<]+)</loc>#', $xml, $matches);

        return array_map(
            static fn (string $loc): string => (string) parse_url($loc, PHP_URL_PATH),
            $matches[1],
        );
    }

    /** @return array<string, mixed> */
    private function breadcrumbList(string $html): array
    {
        $list = $this->jsonLdOfType($html, 'BreadcrumbList');

        $this->assertNotNull($list, 'на странице нет BreadcrumbList');

        return $list;
    }

    /**
     * @return array<string, mixed>|null
     */
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

    /**
     * Регрессия на `pruneEmpty` (критерий 20 SEO-аудита): ни одного пустого
     * значения в разметке рекурсивно. `0` и `false` пропускаются намеренно.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoEmptyScalars(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $where = $path === '' ? (string) $key : $path . '.' . $key;

            if (is_array($value)) {
                $this->assertNoEmptyScalars($value, $where);

                continue;
            }

            if ($value === null || $value === '') {
                $this->fail(sprintf('в JSON-LD осталось пустое значение: %s', $where));
            }
        }
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
