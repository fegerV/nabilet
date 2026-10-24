<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\DataLayer;
use Nabilet\Tests\Support\TestCase;

/**
 * Обёртка `dataLayer.push` одна на три страницы: событие, посадочная,
 * редакционная.
 *
 * Различается только payload — набор полей действительно принадлежит своему
 * месту. Обёртка нет, и в ней два неочевидных условия: идемпотентность
 * `w.dataLayer || []` (иначе серверный скрипт затрёт очередь уже загруженного
 * GTM) и `JSON_HEX_TAG` (иначе значение с `<` закроет `<script>`). Копия
 * теряет такие детали молча, а расхождение видно в отчётах, а не в тестах.
 */
final class DataLayerTest extends TestCase
{
    public function testPushWrapsThePayloadIntoAnIdempotentScript(): void
    {
        $this->assertSame(
            '<script>(function(w){w.dataLayer=w.dataLayer||[];w.dataLayer.push({"event":"page_view","page_type":"page"});})(window);</script>',
            DataLayer::push(['event' => 'page_view', 'page_type' => 'page']),
        );
    }

    public function testCyrillicStaysReadable(): void
    {
        // JSON_UNESCAPED_UNICODE: иначе отладка страницы превращается в разбор
        // `\u041c\u0435…` прямо в исходнике.
        $out = DataLayer::push(['page_path' => '/city/москва']);

        $this->assertStringContainsString('москва', $out);
    }

    public function testAngleBracketCannotCloseTheScriptTag(): void
    {
        $out = DataLayer::push(['page_slug' => '</script><script>alert(1)</script>']);

        // `JSON_HEX_TAG` превращает `<` в `\u003C`; `/` экранирует сам
        // `json_encode`, поэтому в выводе нет ни закрывающего тега значения,
        // ни открывающего `<script>` из данных.
        $this->assertStringContainsString('\u003C', $out);
        $this->assertSame(1, substr_count($out, '</script>'), 'закрывающий тег должен быть ровно один — свой');
        $this->assertSame(1, substr_count($out, '<script>'), 'открывающий тег тоже только свой');
    }

    public function testUnencodablePayloadProducesNothing(): void
    {
        // Аналитика не имеет права ронять страницу, которую человек пришёл
        // читать из поиска.
        $this->assertSame('', DataLayer::push(['bad' => NAN]));
    }
}
