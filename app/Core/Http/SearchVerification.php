<?php

declare(strict_types=1);

namespace Nabilet\Core\Http;

use Nabilet\Core\Support\MetaTag;

/**
 * Коды подтверждения прав на сайт (Яндекс.Вебмастер, Google Search Console).
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС. Тот же блок из двух `if`-ов стоял в трёх местах:
 * `Content\Services\PageRenderer::headExtras()`,
 * `Seo\Services\LandingPageRenderer::headExtras()` и
 * `Events\Http\EventPageShell::seoVerificationAndGeoTags()`. Каждый докблок
 * ссылался на два других как на «ту же логику», то есть копия была известна —
 * и всё равно жила в трёх экземплярах.
 *
 * ПОЧЕМУ В `Core/Http`, А НЕ В `Seo`. Потребители живут в трёх разных модулях.
 * Положить владельца в `Seo` значило бы добавить ребро `Events → Seo` в граф,
 * где `Seo` уже зависит от `Events` (модели, `EventStatus`) — то есть замкнуть
 * зависимость ещё плотнее. Коды подтверждения при этом не про поисковую
 * оптимизацию как функцию, а про ответ HTTP: один домен, один код на всех
 * страницах. `BrandPalette` лежит здесь же по той же причине — общая
 * презентация трёх рендереров.
 *
 * ПУСТОЕ ЗНАЧЕНИЕ → НЕТ ТЕГА. Не косметика: пустой
 * `<meta name="yandex-verification" content="">` валидатор Яндекс.Вебмастера
 * читает как ошибку, и подтверждение прав не срабатывает. Правило живёт в
 * `MetaTag`, здесь только имена тегов и ключи конфигурации.
 *
 * Конфигурация — `config/nabilet.php` (`SEO_VERIFICATION_*`), читается
 * `config()` напрямую: `Core/Http` не входит в набор чистых каталогов
 * (`tools/verify-purity.php`), поэтому обращение к конфигу здесь законно, в
 * отличие от `Core/Support`.
 */
final class SearchVerification
{
    /**
     * Ключ конфигурации → имя мета-тега.
     *
     * Порядок вывода детерминирован (сначала Яндекс, потом Google): так тесты
     * сравнивают `<head>` как строку, не разбирая её.
     *
     * @var array<string, string>
     */
    private const CODES = [
        'yandex' => 'yandex-verification',
        'google' => 'google-site-verification',
    ];

    /**
     * Теги подтверждения по отдельности — для рендереров, которые добавляют к
     * ним свои (`geo.*` у посадочных и страниц событий) и склеивают общим
     * разделителем.
     *
     * @return list<string>
     */
    public static function tagList(): array
    {
        $tags = [];

        foreach (self::CODES as $configKey => $metaName) {
            $tag = MetaTag::named($metaName, config('nabilet.seo.verification.' . $configKey));

            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /** Готовый блок для `<head>` — или пустая строка, если коды не заданы. */
    public static function tags(): string
    {
        return implode('', self::tagList());
    }
}
