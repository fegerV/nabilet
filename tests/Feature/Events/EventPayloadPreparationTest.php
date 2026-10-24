<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Nabilet\Modules\Events\Http\Requests\StoreEventRequest;
use Nabilet\Modules\Events\Http\Requests\UpdateEventRequest;
use Tests\TestCase;

/**
 * Два входа в подготовку payload — и почему `validated()` не видит её результат.
 *
 * `PreparesEventPayload` зовёт `preparePayload()` из двух мест:
 * `passedValidation()` (нормальный ход валидации) и `validated()`
 * («страховка», если пайп или наследник проскочил первый). Раньше эти два
 * метода были выписаны в каждом Request отдельно, а в `UpdateEventRequest` —
 * дважды подряд, и разойтись они могли молча.
 *
 * Тест фиксирует ТРИ факта, и третий — контринтуитивный:
 *
 *  1. оба входа мутируют ЖИВОЙ источник входных данных (`input()` после
 *     вызова не видит ни `status`, ни не-колоночных полей);
 *  2. отчёт об отброшенных полях ведётся на обоих входах;
 *  3. `validated()` при этом ВОЗВРАЩАЕТ СНИМОК, снятый до `passedValidation()`.
 *     `Illuminate\Validation\Validator::validated()` читает `$this->data`, а
 *     `ValidatesWhenResolvedTrait::validateResolved()` создаёт валидатор
 *     РАНЬШЕ, чем зовёт `passedValidation()`. Поэтому `start_date` (у него
 *     правило есть) в результате ОСТАЁТСЯ — и это норма: запись защищает
 *     `Event::$fillable`, а не этот метод.
 *
 * Факт 3 — не curiosum, а причина дефекта: на нём держалось убеждение
 * «`validated()` перечитывает живой источник», и из-за него афиша
 * (`image_url` → `poster`) не доезжала до колонки. См. `EventPosterMappingTest`.
 *
 * Без БД: валидатор создаётся вручную, ровно в том порядке, в каком его
 * создаёт фреймворк.
 */
final class EventPayloadPreparationTest extends TestCase
{
    public function test_the_validated_entry_point_prepares_the_payload_too(): void
    {
        $request = $this->request(UpdateEventRequest::class, [
            'title' => 'Новое название',
            'status' => 'published',
            'start_date' => '2026-11-20',
            'metadata' => ['source' => 'admin'],
        ]);

        // Валидатор создан ДО подготовки payload — как в validateResolved().
        $request->setValidator($this->app['validator']->make($request->all(), $request->rules()));

        $data = $request->validated();

        // Живой источник очищен на обоих входах.
        $this->assertNull($request->input('status'), 'status остался в живом входном наборе');
        $this->assertNull($request->input('start_date'), 'не-колоночное поле осталось в живом входном наборе');

        // Отчёт об отброшенном ведётся и здесь.
        $this->assertArrayHasKey('start_date', $request->droppedFields(), 'второй вход не сообщил об отброшенном start_date');

        // `status` в результате нет — но не потому что вырезан: правила для него
        // нет вообще, и в этом вся защита записи (см. EventPublicationWritePathTest).
        $this->assertArrayNotHasKey('status', $data);
    }

    public function test_validated_returns_the_validators_snapshot_not_the_live_source(): void
    {
        $request = $this->request(UpdateEventRequest::class, [
            'title' => 'Новое название',
            'start_date' => '2026-11-20',
        ]);

        $request->setValidator($this->app['validator']->make($request->all(), $request->rules()));

        $data = $request->validated();

        // Факт 3: не-колоночное поле ЕСТЬ в результате, хотя из живого источника
        // удалено. Снимок снят до подготовки payload.
        $this->assertArrayHasKey('start_date', $data, 'validated() перестал читать снимок валидатора');
        $this->assertNull($request->input('start_date'), 'при этом живой источник обязан быть очищен');
    }

    public function test_both_requests_prepare_the_live_source_the_same_way(): void
    {
        // Создание и правка обязаны резать одно и то же. Раньше это были
        // три независимо выписанные последовательности, и «одинаковость»
        // держалась только на внимании автора.
        $store = $this->request(StoreEventRequest::class, [
            'title' => 'Концерт',
            'slug' => 'koncert',
            'status' => 'published',
            'start_date' => '2026-11-20',
        ]);
        $store->setValidator($this->app['validator']->make($store->all(), $store->rules()));
        $store->validated();

        $update = $this->request(UpdateEventRequest::class, [
            'title' => 'Концерт',
            'status' => 'published',
            'start_date' => '2026-11-20',
        ]);
        $update->setValidator($this->app['validator']->make($update->all(), $update->rules()));
        $update->validated();

        $this->assertNull($store->input('status'));
        $this->assertNull($update->input('status'));
        $this->assertNull($store->input('start_date'));
        $this->assertNull($update->input('start_date'));
        $this->assertSame(['start_date' => '2026-11-20'], $store->droppedFields());
        $this->assertSame(['start_date' => '2026-11-20'], $update->droppedFields());
    }

    /**
     * @param  class-string<StoreEventRequest|UpdateEventRequest>  $class
     * @param  array<string, mixed>  $payload
     * @return StoreEventRequest|UpdateEventRequest
     */
    private function request(string $class, array $payload): StoreEventRequest|UpdateEventRequest
    {
        $request = $class::create('/api/v1/events', 'POST', $payload);
        $request->setContainer($this->app);

        return $request;
    }
}
