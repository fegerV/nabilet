<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nabilet\Core\Http\NotFoundPage;
use Nabilet\Modules\Content\Services\PageRenderer;
use Nabilet\Modules\Content\Services\PageService;

/**
 * Редакционная страница CMS — `GET /page/{slug}` (стратегия SEO, B-2).
 *
 * ЗАЧЕМ. Длинный хвост запросов вида «как вернуть билет», «правила
 * посещения», «о компании» — это редакционный контент, и без адресуемых
 * страниц он не попадает ни в поиск, ни в перелинковку. До B-2 у сайта
 * не было маршрута под этот класс запросов: каталог карточек события и
 * посадочные страницы — это другое.
 *
 * ЧЕРНОВИКИ И ПУСТЫЕ АДРЕСА — 404. Случайный перебор `/page/что-угодно`
 * возвращает 200 на каждом запросе, и таких адресов в индексе становится
 * сколько угодно. Страница, у которой `status != 'published'` или которой
 * нет вовсе, отвечает тем же 404, что и неизвестный маршрут.
 *
 * `X-Robots-Tag` НЕ СТАВИТСЯ. Сейчас у редакционных страниц нет колонки
 * `robots` (стратегия осознанно упростила схему — `pages` хранит только
 * `public_id`/`organization_id`/`title`/`slug`/`content`/`status`/
 * `published_at`), и `<meta name="robots">` в рендере тоже условный.
 * Возврат 404 для черновика — это и есть сигнал «не индексировать», и он
 * важнее тега.
 */
class PageController
{
    public function __construct(
        private readonly PageService $pages,
        private readonly PageRenderer $renderer,
    ) {}

    /** GET /page/{slug}. */
    public function show(Request $request, string $slug): Response
    {
        $document = $this->pages->forSlug($slug);

        if ($document === null) {
            return NotFoundPage::response(
                $request->getPathInfo(),
                'Страница не опубликована или не существует.',
            );
        }

        return $this->renderer->render($document);
    }
}