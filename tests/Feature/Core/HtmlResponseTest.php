<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Nabilet\Core\Http\ContentType;
use Nabilet\Core\Http\HtmlResponse;
use Tests\TestCase;

/**
 * HTML-ответ собирается в одном месте.
 *
 * Пара `response($html)->header('Content-Type', 'text/html; charset=utf-8')`
 * стояла в четырёх местах, и пятое отличалось только статусом. Проверяем
 * именно то, что легко потерять при следующей правке: заголовок, кодировку в
 * нём и сохранение статуса (404 и 410 отдают ту же страницу, но с разным
 * смыслом).
 */
class HtmlResponseTest extends TestCase
{
    public function test_sets_html_content_type_with_charset(): void
    {
        $response = HtmlResponse::of('<p>привет</p>');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<p>привет</p>', $response->getContent());
        self::assertSame(ContentType::HTML, $response->headers->get('Content-Type'));
        self::assertStringContainsString('charset=utf-8', (string) $response->headers->get('Content-Type'));
    }

    public function test_keeps_the_status_code(): void
    {
        foreach ([404, 410, 500] as $status) {
            self::assertSame($status, HtmlResponse::of('', $status)->getStatusCode());
        }
    }

    /** Заголовок стоит сразу, а не появляется при `prepare()` при отправке. */
    public function test_header_is_present_before_sending(): void
    {
        $response = HtmlResponse::of('<p>x</p>');

        self::assertTrue($response->headers->has('Content-Type'));
        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
    }
}
