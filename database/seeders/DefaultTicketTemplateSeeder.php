<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Tickets\Domain\TicketTemplateDefaults;

/**
 * Стандартный макет билета — «по умолчанию для всех мероприятий».
 *
 * ЧТО РЕШАЕТ
 *
 * До этого сидера `ticket_templates` была пуста, и билет собирался фолбэком
 * внутри `TicketPdfRenderer`. Фолбэк рисовал нормальный билет, но его нельзя
 * было открыть в конструкторе: в списке шаблонов было пусто, а «создать»
 * начинало с белого холста. То есть «билет по умолчанию» существовал, но
 * отредактировать его под конкретное мероприятие было нечем — приходилось
 * рисовать заново.
 *
 * Теперь макет лежит в БД, виден в «Шаблоны билетов», резолвится как макет по
 * умолчанию (`TicketTemplateResolver::defaultTemplate()` — первый активный
 * макет организации) и копируется кнопкой «Дублировать» под отдельное
 * мероприятие.
 *
 * ИДЕМПОТЕНТНОСТЬ
 *
 * `firstOrCreate` по паре (организация, имя): повторный `db:seed` не создаёт
 * второй «Стандартный билет» и НЕ перезаписывает правки, которые администратор
 * уже сделал в конструкторе. Иначе деплой молча откатывал бы оформление.
 */
final class DefaultTicketTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $organizationId = $this->resolveOrganizationId();

        if ($organizationId === null) {
            // На свежей установке до `NabiletAdminSeeder` организации ещё нет —
            // это штатно (сидер вызывается последним, но порядок может быть
            // нарушен вручную). Молча выходим: билет всё равно отрендерится
            // фолбэком, а повторный сид создаст запись.
            return;
        }

        $exists = DB::table('ticket_templates')
            ->where('organization_id', $organizationId)
            ->where('name', TicketTemplateDefaults::NAME)
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();

        DB::table('ticket_templates')->insert([
            'public_id' => (string) \Illuminate\Support\Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => TicketTemplateDefaults::NAME,
            'format' => TicketTemplateDefaults::FORMAT,
            'width' => TicketTemplateDefaults::WIDTH,
            'height' => TicketTemplateDefaults::HEIGHT,
            'template_json' => json_encode(
                TicketTemplateDefaults::config(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Организация-владелец макета.
     *
     * Тот же порядок, что в `SurgutVenuesSeeder`: явный ключ конфигурации →
     * организация администратора → первая организация. Дублируется намеренно:
     * сидеры запускаются по отдельности (`--class=`), и общая зависимость между
     * ними сделала бы каждый из них неработоспособным в одиночку.
     */
    private function resolveOrganizationId(): ?int
    {
        $configured = config('nabilet.seed_venues_organization_id');

        if ($configured !== null && $configured !== '') {
            $id = DB::table('organizations')->where('id', (int) $configured)->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        $adminOrganization = DB::table('user_organization')
            ->join('roles', 'roles.id', '=', 'user_organization.role_id')
            ->where('roles.slug', 'admin')
            ->orderBy('user_organization.user_id')
            ->value('user_organization.organization_id');

        if ($adminOrganization !== null) {
            return (int) $adminOrganization;
        }

        $first = DB::table('organizations')->orderBy('id')->value('id');

        return $first !== null ? (int) $first : null;
    }
}
