<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запрещает индексацию служебных страниц (ТЗ §38).
 *
 * ПОЧЕМУ ЗАГОЛОВОК, А НЕ `<meta name="robots">`
 *   Витрина — SPA с hash-роутингом (`createWebHashHistory`), и сервер отдаёт
 *   один и тот же `dist/index.html` на `/`, `/checkout`, `/tickets` и любой
 *   другой путь. Мета-тег пришлось бы вставлять в HTML на каждый такой путь,
 *   то есть заводить серверную обёртку под каждую служебную страницу —
 *   ровно та работа, которой мы избегаем. `X-Robots-Tag` — заголовок ответа:
 *   он не зависит от содержимого HTML, действует на весь ответ целиком и
 *   распознаётся Google и Яндексом наравне с мета-тегом.
 *
 * ЧТО ИМЕННО ЗАКРЫВАЕТСЯ
 *   - `/checkout`, `/payment/*`, `/cart` — оформление заказа и оплата:
 *     персональные данные, нулевая уникальная ценность;
 *   - `/tickets` — выпущенные билеты. Эндпоинт `GET /api/v1/my-tickets`
 *     СОЗНАТЕЛЬНО вынесен из-под `auth:api` и скоупится по заголовку
 *     `X-Cart-Token`, чтобы гость получил билет без регистрации. Это делает
 *     его публичным по дизайну, и попадание его URL в индекс — утечка;
 *   - `/admin/*` — панель управления. Защищена токеном в localStorage, но
 *     URL-ы утекают через Referer и скриншоты.
 *
 * ЧЕГО ЗДЕСЬ НЕТ
 *   `/event/*` не закрывается — это SEO-страницы мероприятий, ради которых
 *   весь модуль и существует. Права на `noindex` у конкретного события живут
 *   в колонке `events.robots` и читаются `EventPageController`.
 *
 * ПРАВИЛО РАСШИРЕНИЯ
 *   Паттерны перечислены явно и по одному. Не заменяйте это на «закрыть всё,
 *   кроме /event/*»: такой список опасен тем, что новая полезная страница
 *   (например, будущий `/venue/{slug}`) молча окажется неиндексируемой, и
 *   узнается об этом через месяц по упавшему трафику.
 */
final class PreventIndexingOfServicePages
{
    public const HEADER = 'X-Robots-Tag';

    /**
     * Пути, ответы которых не должны попадать в индекс.
     *
     * Паттерны — синтаксис `Request::is()` (поддерживает `*`).
     *
     * `api/v1/my-tickets` и `api/v1/me/*` перечислены явно, хотя API целиком
     * закрыт в `robots.txt`. Причина: `robots.txt` НЕ отменяет индексацию —
     * он лишь просит робота не обходить URL. Если на такую страницу ведёт
     * внешняя ссылка, она попадёт в индекс даже под запретом. `X-Robots-Tag`
     * — единственный способ сказать «не индексировать» императивно.
     *
     * @var list<string>
     */
    private const SERVICE_PATHS = [
        'checkout',
        'checkout/*',
        'payment',
        'payment/*',
        'cart',
        'tickets',
        'admin',
        'admin/*',
        'install',
        'install/*',
        // API-эндпоинты, отдающие персональные данные по токену корзины.
        'api/v1/my-tickets',
        'api/v1/my-tickets/*',
        'api/v1/me',
        'api/v1/me/*',
        'api/v1/tickets',
        'api/v1/tickets/*',
        // Диагностика системы: версии PHP/Laravel, геометрия диска, логи.
        // Уже закрыта токеном, но URL-ы утекают через Referer и скриншоты.
        'api/v1/admin/system/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isServicePath($request)) {
            return $response;
        }

        // `noindex` — не индексировать страницу. `nofollow` НЕ ставим: билеты
        // содержат ссылки на схему зала и поддержку, и запрет обхода этих
        // ссылок не даёт ничего, а полезные сигналы забирает.
        //
        // `noarchive` — чтобы Google не держал копию страницы с персональными
        // данными заказа в кэше после того, как сама страница уже удалена.
        $response->headers->set(
            self::HEADER,
            'noindex, noarchive',
            false
        );

        return $response;
    }

    private function isServicePath(Request $request): bool
    {
        foreach (self::SERVICE_PATHS as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
