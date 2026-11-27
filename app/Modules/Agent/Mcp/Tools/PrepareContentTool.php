<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Nabilet\Modules\Agent\Mcp\Tools\Concerns\PreparesAgentAction;
use Nabilet\Modules\Agent\Services\AgentActionService;
use Nabilet\Modules\Agent\Support\AgentCapabilities;

/**
 * Общая основа prepare-инструментов контента.
 *
 * Как и остальные write-инструменты, НИЧЕГО не меняет в бизнес-данных:
 * сохраняет снимок в agent_actions и возвращает action_public_id. Человек
 * одобряет снимок в админке, затем confirm_agent_action исполняет его.
 */
abstract class PrepareContentTool extends Tool
{
    use PreparesAgentAction;

    abstract protected function section(): string;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Заголовок страницы или мероприятия.'),
            'slug' => $schema->string()->description('Латинский адрес страницы.'),
            'content' => $schema->string()->description('HTML-содержимое страницы.'),
            'public_id' => $schema->string()->description('Публичный ID страницы/мероприятия из search.'),
            'config' => $schema->object()->description('Только ключи с префиксом theme.'),
            'entity_type' => $schema->string()->description('page или event.'),
            'entity_id' => $schema->integer()->description('Числовой ID страницы или события.'),
            'locale' => $schema->string()->description('Язык, по умолчанию ru.'),
            'description' => $schema->string()->description('SEO-описание.'),
            'canonical_url' => $schema->string()->description('Канонический адрес.'),
            'og_image' => $schema->string()->description('URL Open Graph изображения.'),
            'short_description' => $schema->string()->description('Краткое описание мероприятия.'),
            'starts_at' => $schema->string()->description('Начало в формате YYYY-MM-DD HH:MM:SS.'),
            'ends_at' => $schema->string()->description('Окончание в формате YYYY-MM-DD HH:MM:SS.'),
            'timezone' => $schema->string()->description('Часовой пояс, например Asia/Yekaterinburg.'),
            'organization_id' => $schema->integer()->description('Организация, если у токена их несколько.'),
        ];
    }

    /** @return Response|ResponseFactory */
    public function handle(Request $request)
    {
        $section = $this->section();
        $rules = match ($section) {
            'createPage' => [
                'title' => ['required', 'string', 'max:500'],
                'slug' => ['nullable', 'string', 'max:255'],
                'content' => ['nullable', 'string', 'max:60000'],
            ],
            'updatePage' => [
                'public_id' => ['required', 'string', 'max:64'],
                'title' => ['sometimes', 'string', 'max:500'],
                'slug' => ['sometimes', 'string', 'max:255'],
                'content' => ['sometimes', 'string', 'max:60000'],
            ],
            'updateTheme' => ['config' => ['required', 'array', 'min:1', 'max:100']],
            'updateSeo' => [
                'entity_type' => ['required', 'string', 'in:page,event'],
                'entity_id' => ['required', 'integer', 'min:1'],
                'locale' => ['sometimes', 'string', 'max:10'],
                'title' => ['sometimes', 'nullable', 'string', 'max:500'],
                'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'canonical_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
                'og_image' => ['sometimes', 'nullable', 'url', 'max:2048'],
            ],
            'updateEvent' => [
                'public_id' => ['required', 'string', 'max:64'],
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string', 'max:60000'],
                'short_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'starts_at' => ['sometimes', 'date'],
                'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
                'timezone' => ['sometimes', 'string', 'max:64'],
            ],
            default => [],
        };
        $common = ['organization_id' => ['sometimes', 'integer', 'min:1']];
        $validated = $request->validate(array_merge($rules, $common));

        if ($section === 'updatePage' && count(array_intersect_key($validated, array_flip(['title', 'slug', 'content']))) === 0) {
            return Response::error('Укажите хотя бы одно поле для обновления страницы.');
        }
        if ($section === 'updateEvent' && count(array_intersect_key($validated, array_flip([
            'title', 'description', 'short_description', 'starts_at', 'ends_at', 'timezone',
        ]))) === 0) {
            return Response::error('Укажите хотя бы одно поле для обновления мероприятия.');
        }

        [$action, $summary] = $this->describe($section, $validated);

        return $this->stage(
            $request,
            $action,
            AgentCapabilities::CONTENT_WRITE,
            $validated,
            $summary,
        );
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array{0: string, 1: string}
     */
    private function describe(string $section, array $v): array
    {
        return match ($section) {
            'createPage' => [
                AgentActionService::ACTION_CREATE_PAGE,
                sprintf('Создать черновик страницы «%s»', $v['title']),
            ],
            'updatePage' => [
                AgentActionService::ACTION_UPDATE_PAGE,
                sprintf('Обновить страницу %s (%s)', $v['public_id'], implode(', ', array_keys(array_intersect_key($v, array_flip(['title', 'slug', 'content']))))),
            ],
            'updateTheme' => [
                AgentActionService::ACTION_UPDATE_THEME,
                'Изменить настройки темы: ' . implode(', ', array_keys($v['config'])),
            ],
            'updateSeo' => [
                AgentActionService::ACTION_UPDATE_SEO,
                sprintf('Обновить SEO для %s #%d (%s)', $v['entity_type'], $v['entity_id'], $v['locale'] ?? 'ru'),
            ],
            'updateEvent' => [
                AgentActionService::ACTION_UPDATE_EVENT,
                sprintf('Обновить мероприятие %s (%s)', $v['public_id'], implode(', ', array_keys(array_intersect_key($v, array_flip([
                    'title', 'description', 'short_description', 'starts_at', 'ends_at', 'timezone',
                ]))))),
            ],
            default => throw new \LogicException('Неизвестный раздел подготовки контента.'),
        };
    }
}
