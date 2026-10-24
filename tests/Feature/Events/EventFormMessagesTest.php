<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Nabilet\Modules\Events\Http\Requests\StoreEventRequest;
use Nabilet\Modules\Events\Http\Requests\UpdateEventRequest;
use Tests\TestCase;

/**
 * Словарь отказов формы мероприятия — одно место на Store и Update.
 *
 * Массив `messages()` стоял в обоих запросах побайтово одинаково, и рядом тем
 * же дублем стояло правило `poster_file`. Тесты проверяют не «работает», а
 * СОГЛАСОВАННОСТЬ: сообщение обязано называть то же число, которое enforce'ит
 * правило. Раньше расхождение было молчаливым — поднять потолок и не тронуть
 * текст значило заставить форму врать пользователю.
 */
class EventFormMessagesTest extends TestCase
{
    public function testBothRequestsGiveTheSameMessages(): void
    {
        $store = StoreEventRequest::create('/api/v1/events', 'POST', []);
        $update = UpdateEventRequest::create('/api/v1/events/1', 'PATCH', []);

        $this->assertSame(
            $store->messages(),
            $update->messages(),
            'Store и Update разошлись в сообщениях: словарь снова стал копией',
        );
    }

    public function testThePosterLimitMessageStatesTheNumberTheRuleEnforces(): void
    {
        $request = StoreEventRequest::create('/api/v1/events', 'POST', []);
        $rule = $request->rules()['poster_file'];

        // Правило собирается из той же константы, что и текст.
        $maxRule = null;
        foreach ($rule as $piece) {
            if (str_starts_with((string) $piece, 'max:')) {
                $maxRule = (int) substr((string) $piece, 4);
            }
        }

        $this->assertNotNull($maxRule, 'в правиле poster_file пропало ограничение max:');
        $this->assertSame(StoreEventRequest::POSTER_MAX_KB, $maxRule);

        $expectedMegabytes = intdiv($maxRule, 1024);
        $this->assertSame(
            sprintf('Файл афиши не должен превышать %d МБ.', $expectedMegabytes),
            $request->messages()['poster_file.max'],
            'сообщение называет не тот потолок, который enforce' . 'ит правило',
        );
    }

    public function testThePosterRuleIsTheSameInBothRequests(): void
    {
        $store = StoreEventRequest::create('/api/v1/events', 'POST', [])->rules()['poster_file'];
        $update = UpdateEventRequest::create('/api/v1/events/1', 'PATCH', [])->rules()['poster_file'];

        $this->assertSame($store, $update, 'правило афиши снова стало копией');
        $this->assertContains('mimes:' . StoreEventRequest::POSTER_MIMES, $store);
    }

    /**
     * Оба потребителя читают одну константу.
     *
     * Не подмена значения, а проверка того, что текст берёт число ОТТУДА ЖЕ,
     * откуда правило. Если бы сообщение осталось строкой «5 МБ», это молча
     * разошлось бы с `max:` — и тест выше поймал бы только правило.
     */
    public function testTheMessageReadsTheSameConstantAsTheRule(): void
    {
        $request = StoreEventRequest::create('/api/v1/events', 'POST', []);

        $this->assertStringContainsString(
            (string) intdiv(StoreEventRequest::POSTER_MAX_KB, 1024) . ' МБ',
            $request->messages()['poster_file.max'],
        );
        $this->assertStringContainsString(
            'max:' . StoreEventRequest::POSTER_MAX_KB,
            implode(',', $request->rules()['poster_file']),
        );
    }
}
