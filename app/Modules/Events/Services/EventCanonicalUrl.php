<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Services;

use Nabilet\Modules\Events\Models\Event;

/**
 * Канонический адрес страницы события — единственный владелец правила.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ КЛАСС. Адрес нужен в трёх местах: `<link rel="canonical">`
 * и JSON-LD на странице, `<loc>` в карте сайта и `url` в разметке события.
 * Правило «из БД, но только если ведёт на наш домен» было написано в
 * `EventPageController`, а карта сайта и JSON-LD брали поле из БД напрямую —
 * то есть проверка действовала ровно там, где вредит меньше всего.
 *
 * ПОЧЕМУ НУЖНА ПРОВЕРКА. `events.canonical_url` — свободное поле, которое
 * заполняет администратор. Если туда попадает чужой домен (скопированный адрес
 * конкурента, тестовый стенд, опечатка), Google видит «эта страница — копия вон
 * той» и снимает её с индексации целиком. Ошибка стоит всей органики страницы и
 * ничем не сигнализирует о себе — ни 500, ни в логах. В карте сайта та же
 * строка ещё и нарушает требование «`<loc>` с того же хоста, что и карта».
 *
 * Относительный путь принимается: он по определению наш, и это ровно то, что
 * подставляет сам администратор, когда пишет `/event/…`.
 *
 * Абсолютный адрес принимается только при совпадении хоста с `app.url` или с
 * хостом текущего запроса. Второе нужно для стендов и локальной разработки, где
 * `APP_URL` в `.env` может отставать от фактического адреса.
 *
 * Живёт в `Services`, а не в `Domain`: правило читает `config()` и `route()`, а
 * доменный слой обязан работать без фреймворка (`tools/verify-purity.php`).
 */
final class EventCanonicalUrl
{
    /**
     * Адрес страницы события: из БД, если ему можно доверять, иначе вычисленный.
     */
    public function for(Event $event): string
    {
        return $this->resolve(
            is_string($event->canonical_url) ? $event->canonical_url : null,
            (string) $event->slug,
            $event->public_id,
        );
    }

    /**
     * То же правило, но по частям: поле из БД, slug и public_id.
     *
     * Нужно JSON-LD: `StructuredDataService::generateEventData()` собирается из
     * массива, модели у него на руках нет. Отдельный метод, а не второй вариант
     * правила — вычисленный адрес обязан быть одинаковым во всех трёх местах.
     */
    public function resolve(?string $stored, string $slug, ?string $publicId = null): string
    {
        return $this->normalize($stored) ?? $this->computed($slug, $publicId);
    }

    /**
     * Проверенное значение поля `canonical_url` или `null`, если доверять нельзя.
     *
     * Отдельным методом, потому что JSON-LD собирается из массива, а не из
     * модели: `StructuredDataService::generateEventData()` принимает
     * `canonical_url` строкой, и ему нужна та же проверка без модели.
     */
    public function normalize(?string $stored): ?string
    {
        $stored = trim((string) $stored);

        if ($stored === '') {
            return null;
        }

        $host = parse_url($stored, PHP_URL_HOST);

        // Относительный путь — наш по определению. Абсолютный URL без хоста
        // (`mailto:`, `javascript:`) сюда не попадёт: у них хост тоже `null`,
        // но и `path` не начинается со слэша.
        if ($host === null) {
            return str_starts_with($stored, '/')
                ? rtrim((string) config('app.url'), '/') . $stored
                : null;
        }

        $allowedHosts = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            request()->getHost(),
        ]);

        return in_array($host, $allowedHosts, true) ? $stored : null;
    }

    /** Вычисленный адрес — он верен по построению и проверки не требует. */
    private function computed(string $slug, ?string $publicId = null): string
    {
        return route('events.show', [
            'slug' => $slug,
            'publicId' => $publicId,
        ]);
    }
}
