<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Http\ContentType;
use Nabilet\Tests\Support\TestCase;

/**
 * Типы содержимого: один владелец строк и одно правило про кодировку.
 *
 * `'text/html; charset=utf-8'` был выписан в пяти местах, `'application/json'` —
 * в шести, `'text/xml'` и `'text/csv'` — по разу. Расхождение здесь не падает
 * в тесте: браузер угадывает кодировку, и русский текст превращается в мусор
 * уже в чужом окне.
 *
 * Второй тест — про исключения. `charset` для JSON бессмыслен (UTF-8 предписан
 * RFC 8259), и «улучшение» в виде `application/json; charset=utf-8` ломает
 * часть парсеров. Такое легко добавить из лучших побуждений, поэтому правило
 * закреплено тестом, а не только докблоком.
 */
final class ContentTypeTest extends TestCase
{
    public function testTextualTypesDeclareUtf8Explicitly(): void
    {
        foreach ([ContentType::HTML, ContentType::XML, ContentType::CSV] as $type) {
            $this->assertStringContainsString(
                'charset=utf-8',
                $type,
                'текстовый тип обязан объявлять кодировку в исходнике, а не надеяться на prepare()',
            );
        }
    }

    public function testJsonAndPdfCarryNoCharset(): void
    {
        $this->assertSame('application/json', ContentType::JSON);
        $this->assertSame('application/pdf', ContentType::PDF);
    }

    public function testSitemapStaysTextXml(): void
    {
        // Краулеры исторически ждут `text/xml`; менять на `application/xml`
        // ради формальной точности незачем — см. `SitemapController::xml()`.
        $this->assertTrue(str_starts_with(ContentType::XML, 'text/xml'));
    }
}
