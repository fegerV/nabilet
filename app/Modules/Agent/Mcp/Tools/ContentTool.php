<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Nabilet\Modules\Agent\Services\AgentContentService;

/**
 * Общая основа read-only контентных инструментов и валидаторов.
 *
 * Все аргументы объявлены здесь один раз; конкретный инструмент выбирает
 * только нужный срез сервиса. Это не «универсальный SQL-терминал»: доступны
 * лишь перечисленные в match() методы с жёстко фиксированными аргументами.
 */
abstract class ContentTool extends Tool
{
    abstract protected function section(): string;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Подстрока поиска (название, slug, город, файл).'),
            'public_id' => $schema->string()->description('Публичный ID страницы (26 символов).'),
            'limit' => $schema->integer()->description('Сколько записей вернуть (1..50).')->default(10),
            'hall_id' => $schema->integer()->description('Числовой ID зала из search_halls.'),
            'payload' => $schema->object()->description('Объект схемы/шаблона/мероприятия для валидации.'),
        ];
    }

    public function handle(Request $request)
    {
        $validated = $request->validate([
            'query' => ['sometimes', 'string', 'max:120'],
            'public_id' => ['sometimes', 'string', 'max:64'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'hall_id' => ['sometimes', 'integer', 'min:1'],
            'payload' => ['sometimes', 'array'],
        ]);
        $service = app(AgentContentService::class);
        $section = $this->section();

        $query = isset($validated['query']) ? (string) $validated['query'] : null;
        $limit = (int) ($validated['limit'] ?? 10);
        $payload = isset($validated['payload']) && is_array($validated['payload']) ? $validated['payload'] : [];

        if ($section === 'getPage' && ($validated['public_id'] ?? '') === '') {
            return Response::error('Укажите public_id страницы из search_pages.');
        }
        if ($section === 'getHallSchema' && ! isset($validated['hall_id'])) {
            return Response::error('Укажите hall_id из search_halls.');
        }
        if (in_array($section, ['validateHallSchema', 'validateEvent', 'validateTicketTemplate'], true) && $payload === []) {
            return Response::error('Передайте объект payload для проверки.');
        }

        $report = match ($section) {
            'searchPages' => $service->searchPages($request->user(), $query, $limit),
            'getPage' => $service->getPage($request->user(), (string) $validated['public_id']),
            'searchMedia' => $service->searchMedia($request->user(), $query, $limit),
            'getSiteSettings' => $service->getSiteSettings($request->user()),
            'getThemeSettings' => $service->getThemeSettings($request->user()),
            'getSeoReport' => $service->getSeoReport($request->user()),
            'searchVenues' => $service->searchVenues($request->user(), $query, $limit),
            'searchHalls' => $service->searchHalls($request->user(), $query, $limit),
            'getHallSchema' => $service->getHallSchema($request->user(), (int) $validated['hall_id']),
            'validateHallSchema' => $service->validateHallSchema($payload),
            'validateEvent' => $service->validateEvent($payload),
            'validateTicketTemplate' => $service->validateTicketTemplate($payload),
            'validatePaymentConfiguration' => $service->validatePaymentConfiguration(),
            default => ['error_message' => 'Неизвестный контентный раздел.'],
        };

        if (($report['found'] ?? true) === false) {
            return Response::error('Объект не найден в организациях вашего токена.');
        }
        if (isset($report['error_message'])) {
            return Response::error((string) $report['error_message']);
        }

        return Response::structured([
            'section' => $section,
            'data' => $report,
        ]);
    }
}
