<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Domain;

/**
 * Готовая посадочная страница — города или площадки (аудит P-12).
 *
 * ЗАЧЕМ ТИПИЗИРОВАННЫЙ ОБЪЕКТ, А НЕ МАССИВ. Ровно на этом проекте уже ловили
 * дефект от позиционной сборки строки: `renderSpaShell()` собирал SEO-блок через
 * `sprintf` с восемью `%s`, и один `null` в середине сдвинул все последующие
 * аргументы — теги уехали в чужие места, а ошибки не было ни одной. Здесь
 * страница описывается ИМЕНОВАННЫМИ полями, и рендер не может перепутать
 * `title` с `description`: он обращается к свойствам.
 *
 * Класс ЧИСТЫЙ (без Eloquent, без `config()`, без I/O) — этого требует
 * purity-гейт для каталога Domain, и это же делает объект пригодным для
 * тестов без базы.
 *
 * АДРЕС ХРАНИТСЯ БЕЗ `?page`, А КАНОНИЧЕСКИЙ ВЫЧИСЛЯЕТСЯ. Так было не сразу.
 * Сначала здесь лежало готовое поле `canonical`, и вызывающий код собирал его
 * сам — то есть формула адреса страницы существовала в двух местах (в сервисе и
 * в рендерере для ссылок пагинации). Одна из двух копий неизбежно отстала бы, и
 * разошлась бы она ровно на границе страниц: ссылка «Назад» вела бы на
 * `?page=0`, а `canonical` — на `?page=1`. `pageUrl()` — единственное место,
 * где собирается адрес страницы коллекции.
 */
final class LandingPage
{
    /**
     * @param  string  $heading      H1 страницы
     * @param  string  $title        `<title>`
     * @param  string  $description  `<meta name="description">`
     * @param  string  $intro        абзац вводного текста — единственный уникальный
     *                               текст страницы помимо списка; без него документ
     *                               состоял бы из одних ссылок
     * @param  string  $baseUrl      адрес коллекции БЕЗ `?page` (первая страница)
     * @param  list<array{name: string, url: string}>  $breadcrumbs  уровни для BreadcrumbList
     *                               Имена полей (`name`, а не `label`) совпадают с
     *                               тем, что читает `StructuredDataService::generateBreadcrumbList()`.
     *                               Это не мелочь: промежуточное преобразование
     *                               `label` → `name` — лишний шаг, на котором
     *                               уровень молча выпал бы из разметки, если бы
     *                               ключ был назван иначе.
     * @param  list<array{title: string, url: string, date: ?string, venue: ?string, price: ?string}>  $items
     * @param  list<array{label: string, url: string, count: int}>  $related  перелинковка на соседние коллекции
     * @param  int  $page      номер текущей страницы, от 1
     * @param  int  $lastPage  номер последней страницы, не меньше 1
     * @param  array<string, mixed>|null  $entity  готовый JSON-LD самой сущности страницы
     *                               (`Place` для площадки, `Organization` для организатора),
     *                               либо `null`. Хранится УЖЕ СОБРАННЫМ, а не в виде
     *                               исходных полей: рендер не должен знать, что такое
     *                               «площадка» и какие у неё бывают колонки, — он умеет
     *                               только вставить блок. Кто именно собирает разметку
     *                               (`LandingPageService` через `StructuredDataService`),
     *                               решено там же, где решены заголовок и описание: это
     *                               текст страницы, а не её структура.
     */
    public function __construct(
        public readonly string $heading,
        public readonly string $title,
        public readonly string $description,
        public readonly string $intro,
        public readonly string $baseUrl,
        public readonly array $breadcrumbs,
        public readonly array $items,
        public readonly array $related,
        public readonly int $page = 1,
        public readonly int $lastPage = 1,
        public readonly ?array $entity = null,
    ) {}

    /**
     * Канонический адрес текущей страницы.
     *
     * Для первой страницы — адрес коллекции без параметров: `?page=1` и голый
     * адрес — это одна и та же страница, и если карточка ссылается на один из
     * них, а canonical указывает на другой, поисковик видит два адреса с
     * одинаковым содержимым.
     */
    public function canonical(): string
    {
        return self::pageUrlFor($this->baseUrl, $this->page);
    }

    /**
     * Адрес произвольной страницы коллекции — для ссылок пагинации.
     */
    public function pageUrl(int $page): string
    {
        return self::pageUrlFor($this->baseUrl, $page);
    }

    /**
     * Правило адресации страниц коллекции — единственная его реализация.
     *
     * Статический, потому что им пользуется и вызывающий код: хлебные крошки
     * строятся до создания объекта, а последний их уровень обязан совпадать с
     * canonical. Второй копии этой формулы быть не должно — она разошлась бы
     * ровно на границе страниц, и ссылка «Назад» вела бы на `?page=0`.
     */
    public static function pageUrlFor(string $baseUrl, int $page): string
    {
        return $page <= 1 ? $baseUrl : $baseUrl . '?page=' . max(1, $page);
    }

    /**
     * Есть ли что показывать.
     *
     * Пустая посадочная страница — это «тонкая страница»: заголовок, вводная
     * фраза и ноль ссылок. Такие страницы поисковик понижает, причём понижение
     * распространяется на весь раздел, а не только на сам пустой URL. Поэтому
     * пустая коллекция — это отсутствие страницы (404), а не пустой документ;
     * решение принимает вызывающий код, а не рендер.
     *
     * Тот же признак закрывает и выход за последнюю страницу: `?page=99` при
     * трёх страницах даёт пустой список, то есть 404 — и это верно, потому что
     * такой страницы не существует. Иначе перебор `?page=N` роботом создавал бы
     * неограниченное число «тонких» адресов под живым кодом ответа.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_column($this->items, 'url');
    }
}
