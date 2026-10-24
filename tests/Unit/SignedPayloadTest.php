<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\SignedPayload;
use Nabilet\Tests\Support\TestCase;

/**
 * Байты, которые подписываются: один владелец флагов сериализации.
 *
 * Тесты фиксируют именно ТЕ свойства, ради которых флаги выбраны: кириллица
 * остаётся кириллицей, `/` не экранируется, порядок ключей не переставляется.
 * Если владелец потеряет флаг, подпись честной стороны перестанет сходиться —
 * и это должно падать здесь, а не в проде у подписчика.
 */
final class SignedPayloadTest extends TestCase
{
    public function testCyrillicIsNotEscaped(): void
    {
        $json = SignedPayload::encode(['name' => 'Сургут']);

        $this->assertNotNull($json);
        $this->assertStringContainsString('Сургут', (string) $json);
        $this->assertSame(0, substr_count((string) $json, '\\u'), 'кириллица не должна превращаться в \\uXXXX');
    }

    public function testSlashesAreNotEscaped(): void
    {
        $json = SignedPayload::encode(['url' => 'https://example.com/a/b']);

        $this->assertSame('{"url":"https://example.com/a/b"}', $json);
    }

    public function testKeyOrderIsPreserved(): void
    {
        $this->assertSame('{"b":1,"a":2}', SignedPayload::encode(['b' => 1, 'a' => 2]));
    }

    public function testScalarsAndNestedStructuresRoundTrip(): void
    {
        $payload = [
            'id' => 42,
            'ok' => true,
            'none' => null,
            'list' => [1, 2],
            'nested' => ['event' => 'order.paid', 'data' => ['amount' => 100.50]],
        ];

        $json = SignedPayload::encode($payload);

        $this->assertNotNull($json);
        $this->assertSame($payload, json_decode((string) $json, true));
    }

    public function testEncodingIsDeterministic(): void
    {
        $payload = ['event' => 'order.paid', 'data' => ['order_id' => 42]];

        $this->assertSame(SignedPayload::encode($payload), SignedPayload::encode($payload));
    }

    /**
     * `null` — «сериализовать не удалось», а не «пусто».
     *
     * Ресурс не кодируется в JSON, и вызывающий обязан узнать об этом до того,
     * как считать подпись: политика отказа (исключение, `false`, остановка
     * доставки) у каждого своя, поэтому решение остаётся за ним.
     */
    public function testUnencodablePayloadIsNull(): void
    {
        $handle = fopen('php://memory', 'r');

        try {
            $this->assertNull(SignedPayload::encode(['handle' => $handle]));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Пустая строка — валидный результат кодирования.
     *
     * `null` и `''` нельзя смешивать: первое — отказ, второе — подписанные
     * пустые байты. Смешав их, вызывающий не отличит «сломалось» от «пусто».
     */
    public function testEmptyStringEncodesToAQuotedEmptyString(): void
    {
        $this->assertSame('""', SignedPayload::encode(''));
    }
}
