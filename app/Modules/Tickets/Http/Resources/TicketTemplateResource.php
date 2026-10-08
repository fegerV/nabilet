<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Шаблон билета для админки.
 *
 * `template_json` отдаётся ОБЪЕКТОМ, а не строкой. Модель кастит колонку в
 * `array`, поэтому наружу уже приходит распакованная структура; конструктор
 * (`TicketBuilder.vue`) читает `template_json.elements`. Если отдать строку,
 * администратор увидит пустой холст — именно так и происходило, когда payload
 * собирался через двойное `JSON.stringify`.
 *
 * `organization_id` не отдаём: он всегда равен организации из токена
 * (см. `TicketTemplateController`), и лишнее поле в ответе только сбивает с толку.
 */
class TicketTemplateResource extends JsonResource
{
    public function toArray($request): array
    {
        $template = $this->resource;

        return [
            'id' => (int) $template->id,
            'public_id' => (string) $template->public_id,
            'name' => (string) $template->name,
            'format' => (string) $template->format,
            'width' => (int) $template->width,
            'height' => (int) $template->height,
            'template_json' => $template->template_json ?? ['backgroundColor' => '#ffffff', 'elements' => []],
            'active' => (bool) $template->active,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
