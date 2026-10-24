<?php

declare(strict_types=1);

namespace Nabilet\Core\Http;

use Illuminate\Http\Response;

/**
 * Брендированная HTML-страница 404 (SEO-аудит P-16).
 *
 * ЗАЧЕМ. До неё «страницы 404» не существовало как документа. Произвольный путь
 * (`/ljkbdf`) доходил до Laravel и получал ответ фреймворка, а `/event/{несуществующий}`
 * — JSON-конверт §66. Статус в обоих случаях был верный, а вот тело — нет:
 *
 *   • JSON на человекочитаемом URL. `/event/…` — это адрес, который человек
 *     открывает из поисковой выдачи или из письма. Увидеть `{"error":{"code":…}}`
 *     в браузере означает «сайт сломан», а не «страницы больше нет»;
 *   • ноль навигации. Пользователь, пришедший по устаревшей ссылке, уходил
 *     в тупик: на странице не было ни одной ссылки, тогда как единственное
 *     полезное действие в этот момент — вернуться в афишу. Для поисковика это
 *     тоже потеря: `follow` по ссылке передаёт вес на каталог, а тупик — нет.
 *
 * ПОЧЕМУ СОБСТВЕННЫЙ HTML, А НЕ ОБОЛОЧКА ВИТРИНЫ. Страница обязана работать,
 * когда `dist/index.html` не собран: сломанная сборка не должна превращать
 * «страница не найдена» в «сервер недоступен». Здесь нет ни одного внешнего
 * ресурса (шрифтов, скриптов, картинок) — только встроенные стили и две ссылки,
 * поэтому документ отдаётся корректно и без сети, и без сборки.
 *
 * ПОЧЕМУ `noindex, follow`. `noindex` — чтобы брендированное тело не попало в
 * индекс как «тонкая страница»: код ответа 404 для этого достаточен, но мета-тег
 * закрывает случай, когда робот пришёл по внешней ссылке. `follow` — потому что
 * единственный осмысленный сигнал с этой страницы ведёт в каталог.
 *
 * ЦВЕТА — ИЗ `BrandPalette`, ЕДИНСТВЕННОГО ВЛАДЕЛЬЦА ПАЛИТРЫ, А НЕ КОПИЯ.
 * Раньше страница держала собственные `COLOR_*` константы с теми же шестью
 * HEX-значениями — ровно та «вторая копия», от которой предостерегает докблок
 * `BrandPalette`. Теперь шаблон ссылается на `BrandPalette::CANVAS` и т. д., и
 * правка бренда не может разойтись между 404 и посадочными страницами.
 */
final class NotFoundPage
{
    /**
     * Собрать ответ 404.
     *
     * @param  string|null  $requestedPath  путь запроса — показывается пользователю
     * @param  string|null  $reason         пояснение под заголовком (например, для события)
     */
    public static function response(?string $requestedPath = null, ?string $reason = null): Response
    {
        return self::page(
            status: 404,
            headline: 'Страница не найдена',
            requestedPath: $requestedPath,
            reason: $reason,
            explanation: 'Возможно, мероприятие завершилось, было снято с продажи '
                . 'или адрес введён с опечаткой.',
        );
    }

    /**
     * Собрать ответ 410 Gone — та же страница, другой смысл.
     *
     * Отдельный вход, а не параметр у `response()`: 404 и 410 — РАЗНЫЕ состояния
     * ресурса, и текст на странице обязан это отражать. «Страница не найдена»
     * навсегда удалённого мероприятия заставляет человека искать опечатку в
     * адресе, которой там нет.
     */
    public static function gone(?string $requestedPath = null, ?string $reason = null): Response
    {
        return self::page(
            status: 410,
            headline: 'Мероприятие удалено',
            requestedPath: $requestedPath,
            reason: $reason,
            explanation: 'Эта страница снята с сайта навсегда. Актуальные события — в афише.',
        );
    }

    /**
     * HTML документа.
     *
     * Путь запроса ЭКРАНИРУЕТСЯ: он приходит из URL и может содержать что угодно,
     * включая `<script>`. Это классическая reflected-XSS через страницу ошибки —
     * её показывают ровно тем, кто перебирает адреса.
     */
    private static function page(
        int $status,
        string $headline,
        ?string $requestedPath,
        ?string $reason,
        string $explanation,
    ): Response {
        $path = trim((string) $requestedPath);

        $pathBlock = $path === '' || $path === '/'
            ? ''
            : sprintf(
                '<p class="path">Запрошенный адрес: <code>%s</code></p>',
                htmlspecialchars($path, ENT_QUOTES)
            );

        $reasonBlock = $reason === null || trim($reason) === ''
            ? ''
            : sprintf('<p class="reason">%s</p>', htmlspecialchars(trim($reason), ENT_QUOTES));

        return response(self::template([
            '{{headline}}' => htmlspecialchars($headline, ENT_QUOTES),
            '{{explanation}}' => htmlspecialchars($explanation, ENT_QUOTES),
            '{{reason}}' => $reasonBlock,
            '{{path}}' => $pathBlock,
        ]), $status)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param  array<string, string>  $blocks
     */
    private static function template(array $blocks): string
    {
        return strtr(
            <<<'HTML'
            <!DOCTYPE html>
            <html lang="ru">
            <head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <meta name="robots" content="noindex, follow" />
            <title>{{headline}} — NABILET</title>
            <style>
              :root { color-scheme: dark; }
              * { box-sizing: border-box; }
              body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
                background: {{canvas}};
                color: {{content}};
                font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
              }
              .card {
                width: 100%;
                max-width: 560px;
                background: {{surface}};
                border: 1px solid rgb(255 255 255 / 0.08);
                border-radius: 16px;
                padding: 32px 28px;
              }
              .bar {
                width: 64px;
                height: 4px;
                border-radius: 999px;
                background: linear-gradient(90deg, {{brand}} 0%, {{accent}} 100%);
              }
              h1 { margin: 20px 0 0; font-size: 28px; line-height: 1.2; }
              p { margin: 12px 0 0; color: {{muted}}; }
              .reason { color: {{content}}; }
              .path code {
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 14px;
                word-break: break-all;
                color: {{muted}};
              }
              a.cta {
                display: inline-block;
                margin-top: 24px;
                padding: 12px 22px;
                border-radius: 999px;
                background: linear-gradient(135deg, {{brand}} 0%, {{accent}} 130%);
                color: #fff;
                font-weight: 600;
                text-decoration: none;
              }
              a.cta:focus-visible { outline: 3px solid {{accent}}; outline-offset: 3px; }
              .brand { margin-top: 24px; font-size: 12px; letter-spacing: .12em; text-transform: uppercase; color: {{muted}}; }
            </style>
            </head>
            <body>
              <main class="card">
                <div class="bar" aria-hidden="true"></div>
                <h1>{{headline}}</h1>
                {{reason}}
                <p>{{explanation}}</p>
                {{path}}
                <a class="cta" href="/">Перейти к афише</a>
                <p class="brand">NABILET — билеты на события</p>
              </main>
            </body>
            </html>
            HTML,
            $blocks + [
                '{{canvas}}' => BrandPalette::CANVAS,
                '{{surface}}' => BrandPalette::SURFACE,
                '{{content}}' => BrandPalette::CONTENT,
                '{{muted}}' => BrandPalette::MUTED,
                '{{brand}}' => BrandPalette::BRAND,
                '{{accent}}' => BrandPalette::ACCENT,
            ]
        );
    }
}
