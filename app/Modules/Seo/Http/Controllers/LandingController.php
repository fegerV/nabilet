<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nabilet\Core\Http\NotFoundPage;
use Nabilet\Modules\Seo\Services\CatalogService;
use Nabilet\Modules\Seo\Services\LandingPageRenderer;
use Nabilet\Modules\Seo\Services\LandingPageService;

/**
 * Посадочные страницы: площадка, город, категория, фасет «город × категория»
 * и организатор (аудит P-12).
 *
 * ЗАЧЕМ. Длинный хвост запросов — «концерт в Кремлёвском дворце», «афиша
 * Сургута», «театр в Казани» — не имеет адресуемых страниц: каталог это
 * hash-SPA, у которого один индексируемый URL (`/`), а фильтры живут в
 * параметрах запроса и роботом не обходятся. Пока страниц нет, каждое такое
 * намерение упирается в потолок: у сайта физически нет адреса, который можно
 * показать в выдаче под этот запрос.
 *
 * СТРАНИЦА ОТДАЁТСЯ ТОЛЬКО НЕПУСТОЙ. Пустая коллекция — это 404, а не документ
 * из заголовка и нуля ссылок. «Тонкая страница» понижает не только себя: Google
 * распространяет оценку на раздел, поэтому десяток пустых городов в индексе
 * стоил бы позиций и по непустым. Отсутствие страницы честнее пустой страницы —
 * и для робота, и для человека, которому нечего делать на «афише без афиши».
 *
 * КАЖДАЯ НЕСУЩЕСТВУЮЩАЯ СТРАНИЦА — 404, А НЕ РЕДИРЕКТ. Редирект на каталог
 * выглядел бы полезнее, но он превратил бы любой перебор адресов в
 * бесконечность: робот получает 200 на каждый `/city/что-угодно`, и таких
 * адресов в индексе становится сколько угодно. 404 закрывает вопрос.
 */
class LandingController
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly LandingPageService $pages,
        private readonly LandingPageRenderer $renderer,
    ) {}

    /** GET /venue/{slug} — афиша одной площадки. */
    public function venue(Request $request, string $slug): Response
    {
        $venue = $this->catalog->venueBySlug($slug);

        if ($venue === null) {
            return NotFoundPage::response(
                $request->getPathInfo(),
                sprintf('Площадка «%s» не найдена.', $slug),
            );
        }

        $page = $this->pages->forVenue($venue, $this->requestedPage($request));

        if ($page->isEmpty()) {
            return NotFoundPage::response(
                $request->getPathInfo(),
                sprintf('На площадке «%s» пока нет мероприятий.', $venue->name),
            );
        }

        return $this->renderer->render($page);
    }

    /** GET /city/{slug} — афиша одного города. */
    public function city(Request $request, string $slug): Response
    {
        $city = $this->catalog->cityBySlug($slug);

        if ($city === null) {
            return NotFoundPage::response(
                $request->getPathInfo(),
                sprintf('Город «%s» не найден.', $slug),
            );
        }

        $page = $this->pages->forCity($city, $this->requestedPage($request));

        if ($page->isEmpty()) {
            return NotFoundPage::response(
                $request->getPathInfo(),
                sprintf('В городе «%s» пока нет мероприятий.', $city['name']),
            );
        }

        return $this->renderer->render($page);
    }

    /** GET /category/{slug} — афиша одной категории. */
    public function category(Request $request, string $slug): Response
    {
        $category = $this->catalog->categoryBySlug($slug);

        if ($category === null) {
            return NotFoundPage::response(
                $request->getPathInfo(),
                sprintf('Категория «%s» не найдена.', $slug),
            );
        }

        $page = $this->pages->forCategory($category, $this->requestedPage($request));

        if ($page->isEmpty()) {
            return NotFoundPage::response(
                $request->getPathInfo(),
                sprintf('В категории «%s» пока нет мероприятий.', $category->name),
            );
        }

        return $this->renderer->render($page);
    }

    /**
     * GET /organizer/{slug} — афиша одного организатора.
     *
     * Организатор — фасет каталога, а не место: события собираются со всех его
     * площадок и городов, и сеанс для них не требуется. Смысл страницы — «что
     * проводит этот организатор», и он не зависит от зала. Подробнее — в
     * `CatalogService::eventsAtOrganization()`.
     */
    public function organizer(Request $request, string $slug): Response
    {
        $organization = $this->catalog->organizationBySlug($slug);

        if ($organization === null) {
            return NotFoundPage::response(
                $request->getPathInfo(),
                sprintf('Организатор «%s» не найден.', $slug),
            );
        }

        $page = $this->pages->forOrganizer($organization, $this->requestedPage($request));

        if ($page->isEmpty()) {
            return NotFoundPage::response(
                $request->getPathInfo(),
                sprintf('У организатора «%s» пока нет мероприятий.', $organization->name),
            );
        }

        return $this->renderer->render($page);
    }

    /**
     * GET /city/{slug}/{category} — афиша категории в одном городе.
     *
     * Город проверяется ПЕРВЫМ: если не существует города, нет смысла искать
     * категорию, а сообщение «категория не найдена» на несуществующий город
     * уводило бы человека искать опечатку не в том сегменте адреса.
     */
    public function cityCategory(Request $request, string $citySlug, string $categorySlug): Response
    {
        $city = $this->catalog->cityBySlug($citySlug);

        if ($city === null) {
            return NotFoundPage::response(
                $request->getPathInfo(),
                sprintf('Город «%s» не найден.', $citySlug),
            );
        }

        $category = $this->catalog->categoryBySlug($categorySlug);

        if ($category === null) {
            return NotFoundPage::response(
                $request->getPathInfo(),
                sprintf('Категория «%s» не найдена.', $categorySlug),
            );
        }

        $page = $this->pages->forCityCategory($city, $category, $this->requestedPage($request));

        if ($page->isEmpty()) {
            return NotFoundPage::response(
                $request->getPathInfo(),
                sprintf(
                    'В городе «%s» в категории «%s» пока нет мероприятий.',
                    $city['name'],
                    $category->name,
                ),
            );
        }

        return $this->renderer->render($page);
    }

    /**
     * Номер страницы из `?page`.
     *
     * Из запроса явно, а не аргументом действия: аргумент действия разрешается
     * из ПУТИ, поэтому `?page=2` до него не доехал бы вовсе, и пагинация молча
     * возвращала бы первую страницу на каждом адресе. Та же ловушка уже ловилась
     * в `SitemapController::events()`.
     *
     * Нечисловое значение приводится к 1, отрицательное — тоже: 404 на
     * `?page=abc` был бы наказанием за опечатку в адресной строке, а не защитой.
     */
    private function requestedPage(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }
}
