<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\JsonLd;
use Nabilet\Tests\Support\TestCase;

/**
 * JSON-LD внутри `<script>`: правило экранирования одно на три рендерера.
 *
 * Разметку собирает `StructuredDataService` из названий, которые вводит
 * администратор. `</script>` в описании мероприятия закрывает тег и превращает
 * остаток JSON в текст страницы — это не гипотетический сценарий, а обычная
 * опечатка. `htmlspecialchars()` здесь неприменим (`&quot;` сломал бы JSON),
 * поэтому преобразуются ровно два символа, и преобразование обязано быть
 * одинаковым на странице события, посадочной и редакционной.
 */
final class JsonLdTest extends TestCase
{
    public function testEscapeNeutralisesOnlyAngleBrackets(): void
    {
        $this->assertSame(
            '{"a":"\u003C/script\u003E\u003Cb\u003E"}',
            JsonLd::escape('{"a":"</script><b>"}'),
            'кавычки и фигурные скобки обязаны остаться: иначе JSON перестанет быть JSON',
        );
    }

    public function testEscapedJsonIsStillValidJson(): void
    {
        $json = json_encode(['name' => 'ДК «Нефтяник»', 'html' => '<b>&amp;</b>'], JSON_UNESCAPED_UNICODE);

        $this->assertSame(
            json_decode((string) $json, true),
            json_decode(JsonLd::escape((string) $json), true),
            'экранирование не должно менять данные, только их запись',
        );
    }

    public function testScriptWrapsJsonIntoTheLdJsonTag(): void
    {
        $this->assertSame(
            '<script type="application/ld+json">{"@type":"Article"}</script>',
            JsonLd::script('{"@type":"Article"}'),
        );
    }

    public function testScriptIsEmptyWhenThereIsNoJson(): void
    {
        // Пустой `<script type="application/ld+json"></script>` валидатор
        // Google показывает как ошибку «expected a value», а не как
        // «разметки нет» — поэтому отсутствие данных обязано давать
        // отсутствие тега.
        foreach (['', '   ', false, null, []] as $nothing) {
            $this->assertSame('', JsonLd::script($nothing));
        }
    }

    public function testValueCannotCloseTheScriptTag(): void
    {
        $out = JsonLd::script(json_encode(['description' => '</script><script>alert(1)</script>']));

        $this->assertSame(
            1,
            substr_count($out, '</script>'),
            'закрывающий тег должен быть ровно один — свой',
        );
    }
}
