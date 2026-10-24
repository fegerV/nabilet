<?php

declare(strict_types=1);

namespace Nabilet\Core\Http;

use Illuminate\Http\Response;

/**
 * HTML-ответ: тело, статус и `Content-Type` — единственный владелец сборки.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС. Пара
 * `response($html)->header('Content-Type', 'text/html; charset=utf-8')` стояла
 * в четырёх местах, и пятое (`NotFoundPage`) отличалось только статусом.
 * Копия безобидна ровно до первой правки: `text/html` без `charset` выглядит
 * рабочим, потому что фреймворк дописывает кодировку при отправке, — но
 * заголовок, прочитанный раньше отправки, уже без неё, и «работает» здесь
 * означает «работает, пока никто не прочитал заголовок до `prepare()`».
 *
 * ПОЧЕМУ НЕ ПРОСТО `response($html)`. У Laravel `Response::prepare()` ставит
 * `text/html; charset=utf-8` только когда `Content-Type` НЕ ЗАДАН вовсе.
 * Как только его задаёт мидлвар (например, `ApplySecurityHeaders` или
 * кеширующий слой), значение приходит из мидлвара, и молчаливая зависимость
 * от фреймворка исчезает. Явный заголовок делает тип частью ответа, а не
 * следствием порядка мидлваров.
 */
final class HtmlResponse
{
    /**
     * Собрать HTML-ответ.
     *
     * @param  int  $status  код ответа — 404 и 410 отдают ту же страницу,
     *                       но разный смысл, поэтому статус параметр, а не константа
     */
    public static function of(string $html, int $status = 200): Response
    {
        return response($html, $status)->header('Content-Type', ContentType::HTML);
    }
}
