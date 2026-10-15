<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Services;

use Nabilet\Modules\Tickets\Models\TicketTemplate;

/**
 * Шаблон билета по умолчанию.
 *
 * «По умолчанию» — первый активный макет организации (сортировка по `id`
 * детерминирована: два билета одного заказа не должны разъехаться
 * оформлением). Та же семантика, что у `TicketTemplateResolver::defaultTemplate()`,
 * но с доведением до конца: если активного макета нет вообще, он создаётся из
 * встроенной заготовки, а не возвращается `null`.
 *
 * ЗАЧЕМ СОЗДАНИЕ, А НЕ NULL. Новое мероприятие обязано сразу иметь макет:
 * `EventController::store()` назначает его, когда администратор макет не
 * выбрал. Возврат `null` оставлял бы событие без оформления до тех пор, пока
 * кто-то не соберёт первый шаблон вручную, — а первое мероприятие обычно и
 * создаётся раньше первого шаблона.
 *
 * Заготовка — зеркало `BUILT_IN_LAYOUTS['classic-concert']` из
 * `resources/js/components/TicketBuilder.vue`. Две копии намеренно: фронт
 * рисует холст, бэк — сидирует строку, и общего формата обмена между PHP и
 * Vue в проекте нет. При правке заготовки править обе.
 */
class TicketTemplateService
{
    public const DEFAULT_TEMPLATE_NAME = 'Стандартный';

    /**
     * @return array{backgroundColor: string, elements: list<array<string, mixed>>}
     */
    public static function defaultLayout(): array
    {
        return [
            'backgroundColor' => '#1a1a2e',
            'elements' => [
                ['id' => 1, 'type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 600, 'height' => 400, 'fill' => '#1a1a2e'],
                ['id' => 2, 'type' => 'text', 'x' => 50, 'y' => 50, 'content' => '{{event.name}}', 'fontSize' => 32, 'fontWeight' => 'bold', 'color' => '#ffffff'],
                ['id' => 3, 'type' => 'text', 'x' => 50, 'y' => 100, 'content' => '{{event.date}}', 'fontSize' => 18, 'color' => '#e94560'],
                ['id' => 4, 'type' => 'text', 'x' => 50, 'y' => 150, 'content' => '{{event.venue}}', 'fontSize' => 16, 'color' => '#cccccc'],
                ['id' => 5, 'type' => 'qr', 'x' => 400, 'y' => 250, 'size' => 120],
                ['id' => 6, 'type' => 'text', 'x' => 50, 'y' => 350, 'content' => '{{seat.info}}', 'fontSize' => 14, 'color' => '#ffffff'],
            ],
        ];
    }

    /**
     * Первый активный макет организации, либо созданный «Стандартный».
     *
     * Выключенные макеты (`active = 0`) пропуском не назначаются: именно это
     * проверяет `TicketEmailTemplateTest::test_a_disabled_default_template_is_not_used`
     * для резолвера, и здесь правило то же.
     */
    public function ensureDefaultTemplate(int $organizationId): TicketTemplate
    {
        $existing = TicketTemplate::query()
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return TicketTemplate::query()->create([
            'organization_id' => $organizationId,
            'name' => self::DEFAULT_TEMPLATE_NAME,
            'format' => 'mobile',
            'width' => 600,
            'height' => 400,
            'template_json' => self::defaultLayout(),
            'active' => true,
        ]);
    }
}
