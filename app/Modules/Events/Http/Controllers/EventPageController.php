<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Controllers;

use Nabilet\Modules\Events\Repositories\EventRepository;
use Nabilet\Modules\Events\Services\EventService;
use Nabilet\Modules\Seo\Services\StructuredDataService;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;

/**
 * Серверная обёртка страницы мероприятия для SEO.
 *
 * Поисковые боты не исполняют JavaScript SPA-приложения (или исполняют
 * медленно), поэтому страница события отдаётся как готовый HTML с:
 *   — уникальными <title>, meta description, canonical, og-тегами;
 *   — JSON-LD разметкой schema.org/Event (StructuredDataService);
 *   — телом с контейнером #app, в который Vue монтирует интерактив.
 *
 * Данные всегда из БД по slug — пересборка фронта при новом зале/концерте
 * не нужна: изменяется только запись в таблице events.
 */
class EventPageController
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly EventRepository $events,
        private readonly StructuredDataService $structuredData
    ) {}

    /**
     * GET /event/{slug}/{publicId}
     * GET /event/{slug}            → редирект на полный URL (canonical).
     */
    public function show(string $slug, ?string $publicId = null): Response | RedirectResponse
    {
        $event = $this->events->findBySlug($slug);

        if ($event === null || $event->status === 'draft' || $event->status === 'archived') {
            return response()->json([
                'error' => [
                    'code' => 'EVENT_NOT_FOUND',
                    'message' => sprintf('Мероприятие «%s» не найдено.', $slug),
                ],
            ], 404);
        }

        // Канонический URL: /event/{slug}/{publicId} — без publicId редирект.
        $canonical = route('events.show', [
            'slug' => $event->slug,
            'publicId' => $event->public_id,
        ]);

        if ($publicId === null) {
            return response()->redirectTo($canonical, 301);
        }

        $event->load(['category', 'translations', 'sessions', 'organization', 'tickets']);

        $title = $event->seo_title ?? sprintf('%s — купить билеты', $event->title);
        $description = $event->seo_description
            ?? $event->short_description
            ?? $event->description
            ?? sprintf('Билеты на «%s»: выбор мест на схеме зала, оплата онлайн, QR-билет.', $event->title);

        $jsonLd = json_encode($this->structuredData->generateEventData([
            'title'               => $event->title,
            'short_description'   => $event->short_description,
            'description'         => $event->description,
            'slug'                => $event->slug,
            'public_id'           => $event->public_id,
            'status'              => $event->status,
            'poster'              => $event->poster,
            'cover'               => $event->cover,
            'start_datetime'      => $event->start_datetime,
            'end_datetime'        => $event->end_datetime,
            'canonical_url'       => $canonical,
            'venue'               => ['name' => ''],
            'organization'        => $event->organization?->toArray() ?? ['name' => ''],
        ]));

        $html = $this->renderSpaShell([
            'title'         => $title,
            'description'   => $description,
            'canonical'     => $canonical,
            'image'         => $event->poster ? $this->absoluteUrl($event->poster) : null,
            'jsonLd'        => $jsonLd,
        ]);

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function absoluteUrl(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return config('app.url') . '/storage/' . ltrim($path, '/');
    }

    private function renderSpaShell(array $seo): string
    {
        $indexFile = __DIR__ . '/../../../../../dist/index.html';
        if (! file_exists($indexFile)) {
            return '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
                . '<title>NABILET — билеты на события</title></head>'
                . '<body><div id="app"></div></body></html>';
        }

        $html = file_get_contents($indexFile);

        // Заменяем title/description на уникальные для события.
        $html = preg_replace(
            '/<title>.*?<\/title>/s',
            sprintf('<title>%s</title>', htmlspecialchars($seo['title'], ENT_QUOTES)),
            $html
        );
        $html = preg_replace(
            '/<meta name="description"[^>]*>/',
            sprintf('<meta name="description" content="%s" />', htmlspecialchars($seo['description'], ENT_QUOTES)),
            $html
        );

        // canonical + og + JSON-LD — вставляем перед </head>.
        $seoBlock = sprintf(
            '<link rel="canonical" href="%s" />'
            . '<meta property="og:type" content="website" />'
            . '<meta property="og:title" content="%s" />'
            . '<meta property="og:description" content="%s" />'
            . '<meta property="og:url" content="%s" />'
            . ($seo['image'] ? '<meta property="og:image" content="%s" />' : '')
            . '<script type="application/ld+json">%s</script>',
            htmlspecialchars($seo['canonical'], ENT_QUOTES),
            htmlspecialchars($seo['title'], ENT_QUOTES),
            htmlspecialchars($seo['description'], ENT_QUOTES),
            htmlspecialchars($seo['canonical'], ENT_QUOTES),
            $seo['image'] ? htmlspecialchars($seo['image'], ENT_QUOTES) : null,
            $seo['jsonLd']
        );

        $html = str_replace('</head>', $seoBlock . '</head>', $html);

        return $html;
    }
}