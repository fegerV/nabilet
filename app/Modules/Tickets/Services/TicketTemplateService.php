<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Services;

use Nabilet\Modules\Tickets\Domain\TicketTemplateDefaults;
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
 * ЗАГОТОВКА — ИЗ `TicketTemplateDefaults`, А НЕ СВОЯ. Раньше здесь лежала своя
 * копия макета (600×400, тёмная), и на чистой базе, где сидер ещё не
 * отработал, мероприятие получало её вместо вертикального стандарта: письмо
 * (`TicketPdfRenderer::defaultConfig()`), конструктор (`BUILT_IN_LAYOUTS`) и
 * витрина показывали три разных «билета по умолчанию». Теперь источник один.
 */
class TicketTemplateService
{
    /**
     * Первый активный макет организации, либо созданный стандартный.
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

        $config = TicketTemplateDefaults::config();

        return TicketTemplate::query()->create([
            'organization_id' => $organizationId,
            'name' => TicketTemplateDefaults::NAME,
            'format' => TicketTemplateDefaults::FORMAT,
            'width' => $config['width'],
            'height' => $config['height'],
            'template_json' => $config,
            'active' => true,
        ]);
    }
}
