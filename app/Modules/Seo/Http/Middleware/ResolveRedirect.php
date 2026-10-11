<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Http\Middleware;

use Nabilet\Modules\Seo\Services\RedirectResolver;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Отдаёт постоянный редирект по таблице `redirects` ДО того, как запрос дойдёт
 * до маршрутизатора.
 *
 * ПОЧЕМУ ГЛОБАЛЬНО, А НЕ В ГРУППЕ `web`. Middleware группы запускается только
 * для СОВПАВШЕГО маршрута. Переименованный slug — это ровно тот случай, когда
 * маршрута нет: `/event/old-slug` не совпадает ни с чем, роутер бросает
 * `NotFoundHttpException`, и до группового middleware дело не доходит. Поэтому
 * единственное рабочее место — глобальный стек, где код исполняется до
 * сопоставления с маршрутом и может ответить вместо 404.
 *
 * ЧТО НЕ ПЕРЕХВАТЫВАЕТСЯ.
 *   — не GET/HEAD: редирект адреса не должен менять семантику POST/PUT, иначе
 *     клиент, отправлявший форму, молча потеряет тело запроса;
 *   — `/api/*`: у API свой контракт (§66 envelope) и своя версия в пути;
 *     «301 на страницу» в ответ на API-вызов — это сломанный клиент, а не SEO;
 *   — `/install` и `/install/*`: мастер установки одноразовый и живёт вне
 *     публичной адресной модели.
 *
 * ПОРЯДОК ОТВЕТА. Редирект отдаётся раньше tenancy и раньше проверки прав —
 * осознанно: цель редиректа публична по определению (иначе в таблицу бы её не
 * записали), а лишний запрос к БД за организацией на каждый переход по старому
 * адресу не нужен.
 */
class ResolveRedirect
{
    public function __construct(
        private readonly RedirectResolver $resolver
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        // `up` — health-check (`withRouting(health: '/up')`). Он обязан отвечать
        // даже при недоступной БД: запрос в таблицу `redirects` превратил бы
        // «база лежит» в 500 вместо честного ответа о состоянии.
        if ($request->is('api/*') || $request->is('install') || $request->is('install/*') || $request->is('up')) {
            return $next($request);
        }

        try {
            $redirect = $this->resolver->resolve($request->getPathInfo());
        } catch (Throwable $e) {
            // FAIL-OPEN, и это осознанный выбор. Middleware стоит в ГЛОБАЛЬНОМ
            // стеке: исключение здесь отдало бы 500 на ЛЮБОЙ странице сайта —
            // одна сломанная таблица `redirects` (не накатили миграцию, отвалился
            // коннект) уронила бы витрину целиком. Редирект — удобство поверх
            // работы сайта, поэтому при сбое отдаём страницу как обычно, а
            // проблему фиксируем в логе, где её и надо искать.
            Log::warning('Не удалось разрешить редирект; запрос обслужен без него.', [
                'path' => $request->getPathInfo(),
                'exception' => $e->getMessage(),
            ]);

            return $next($request);
        }

        if ($redirect === null) {
            return $next($request);
        }

        return $this->redirectResponse($redirect->destination, $this->resolver->statusCode($redirect));
    }

    /**
     * Отдельный метод, а не инлайн: `redirect()->to()` строит URL из строки, и
     * здесь важно, что назначение может быть и внешним (`https://…`), и
     * внутренним (`/event/…`). Для внутреннего Laravel сам подставит схему и
     * хост текущего запроса, для внешнего — оставит как есть.
     */
    private function redirectResponse(string $destination, int $statusCode): RedirectResponse
    {
        return redirect()->to($destination, $statusCode);
    }
}
