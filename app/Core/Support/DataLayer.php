<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * Серверный `dataLayer.push` — единственный владелец обёртки.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС. Обёртка
 * `<script>(function(w){w.dataLayer=w.dataLayer||[];w.dataLayer.push(…);})(window);</script>`
 * была выписана в трёх местах: страница события (`EventPageShell`), посадочная
 * (`LandingPageRenderer`) и редакционная (`PageRenderer`). Различался только
 * payload — набор полей, который действительно принадлежит своему месту
 * (`page_slug` у редакционной, `event_id` у события). Сама обёртка — нет.
 *
 * ЧТО ЗДЕСЬ ВАЖНО И ЧТО ЛЕГКО ПОТЕРЯТЬ В КОПИИ.
 *   1. Идемпотентность `w.dataLayer || []`: скрипт стоит в конце `<body>`, и
 *      GTM, если он установлен, уже создал массив. Без `||` мы бы затирали
 *      очередь тег-менеджера.
 *   2. `JSON_HEX_TAG` вместе с `JSON_UNESCAPED_UNICODE`. Первый экранирует `<`
 *      и `>` в `\u003C`/`\u003E` — то же правило, что у `JsonLd::escape()`:
 *      значение, попавшее в payload, не должно уметь закрыть `<script>`. Второй
 *      оставляет кириллицу читаемой, иначе отладка в исходнике страницы
 *      превращается в разбор `\u041c\u0435…`.
 *
 * ПОЧЕМУ ЭТО НЕ ВИТРИНА. `dataLayer` — единственный способ, которым серверный
 * HTML сообщает аналитике тип страницы: у посадочных и редакционных страниц нет
 * JS-бандла витрины, а у страницы события `page_view` уходит только после
 * монтирования Vue. Пока обёртка была скопирована, правка правила (например,
 * добавление `JSON_HEX_TAG`) попадала в одну копию из трёх, и расхождение
 * проявлялось бы в отчётах, а не в тестах.
 */
final class DataLayer
{
    /**
     * `<script>` с `dataLayer.push(payload)` — или пустая строка, если payload
     * не сериализуется.
     *
     * Пустая строка, а не бросание исключения: аналитика не имеет права ронять
     * страницу, которую человек пришёл читать из поиска. Нераскодируемый payload
     * — ошибка сборки данных, и её видно в тестах, а не у покупателя.
     *
     * @param  array<string, scalar|null>  $payload
     */
    public static function push(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        if (! is_string($json)) {
            return '';
        }

        return sprintf(
            '<script>(function(w){w.dataLayer=w.dataLayer||[];w.dataLayer.push(%s);})(window);</script>',
            $json,
        );
    }
}
