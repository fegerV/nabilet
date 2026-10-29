<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Идемпотентный бэкфилл `organization_id` для таблиц класса A (фаза 1).
 *
 * См. `docs/SECURITY-GAPS-REMEDIATION-2026-10-29.md` §3.2/§3.3. Колонки
 * добавляет миграция `2026_10_29_000100_...`; эта команда заполняет их из
 * родителя. Она:
 *
 *   - идемпотентна: трогает только строки с `organization_id IS NULL`, повторный
 *     запуск ничего не меняет;
 *   - транзакционна в dry-run: `--dry-run` выполнит UPDATE внутри транзакции и
 *     откатит её — точный «сколько заполнится» без записи;
 *   - сообщает остаток NULL по каждой таблице: переход к фазе 3 (`NOT NULL`/FK)
 *     разрешён только при нулевом остатке.
 *
 * Строки, которые нельзя отнести к организации (например, корзина без сеанса и
 * без однозначного членства, либо розыгрыш без записей), остаются NULL и
 * попадают в отчёт — их разбирают вручную, а не молча оставляют.
 */
final class TenancyBackfillOrganizationCommand extends Command
{
    /** @var string */
    protected $signature = 'tenancy:backfill-organization
                            {--dry-run : Показать, сколько заполнится, но не записывать}
                            {--table= : Ограничиться одной таблицей}';

    /** @var string */
    protected $description = 'Идемпотентно заполняет organization_id таблиц класса A из родителя';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('table');

        foreach ($this->steps() as $table => $steps) {
            if ($only !== null && $only !== $table) {
                continue;
            }

            foreach ($steps as $label => $sql) {
                $this->runStep($table, $label, $sql, $dryRun);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Все шаги бэкфилла. Ключ — таблица, значение — «метка → SQL».
     *
     * Идентификаторы — константы эталонной схемы
     * (`nabilet_core_spec/migrations.sql`), поэтому SQL строится со строковыми
     * именами, а не с пользовательским вводом.
     *
     * @return array<string, array<string, string>>
     */
    private function steps(): array
    {
        return [
            'sessions' => [
                'из событий' => 'UPDATE sessions s JOIN events e ON e.id = s.event_id SET s.organization_id = e.organization_id WHERE s.organization_id IS NULL',
            ],
            'inventory_items' => [
                'из сеансов' => 'UPDATE inventory_items i JOIN sessions s ON s.id = i.session_id SET i.organization_id = s.organization_id WHERE i.organization_id IS NULL',
            ],
            'carts' => [
                'из сеансов' => 'UPDATE carts c JOIN sessions s ON s.id = c.session_id SET c.organization_id = s.organization_id WHERE c.organization_id IS NULL AND c.session_id IS NOT NULL',
                'из единственного членства' => 'UPDATE carts c JOIN (SELECT uo.user_id, MIN(uo.organization_id) AS org_id FROM user_organization uo GROUP BY uo.user_id HAVING COUNT(DISTINCT uo.organization_id) = 1) m ON m.user_id = c.user_id SET c.organization_id = m.org_id WHERE c.organization_id IS NULL AND c.user_id IS NOT NULL',
            ],
            'cart_items' => [
                'из корзин' => 'UPDATE cart_items ci JOIN carts c ON c.id = ci.cart_id SET ci.organization_id = c.organization_id WHERE ci.organization_id IS NULL',
            ],
            'seat_holds' => [
                'из корзин' => 'UPDATE seat_holds sh JOIN carts c ON c.id = sh.cart_id SET sh.organization_id = c.organization_id WHERE sh.organization_id IS NULL',
                'из сеансов' => 'UPDATE seat_holds sh JOIN sessions s ON s.id = sh.session_id SET sh.organization_id = s.organization_id WHERE sh.organization_id IS NULL',
            ],
            'order_items' => [
                'из заказов' => 'UPDATE order_items oi JOIN orders o ON o.id = oi.order_id SET oi.organization_id = o.organization_id WHERE oi.organization_id IS NULL',
            ],
            'payments' => [
                'из заказов' => 'UPDATE payments p JOIN orders o ON o.id = p.order_id SET p.organization_id = o.organization_id WHERE p.organization_id IS NULL',
            ],
            'payment_transactions' => [
                'из платежей' => 'UPDATE payment_transactions pt JOIN payments p ON p.id = pt.payment_id SET pt.organization_id = p.organization_id WHERE pt.organization_id IS NULL',
            ],
            'refunds' => [
                'из заказов' => 'UPDATE refunds r JOIN orders o ON o.id = r.order_id SET r.organization_id = o.organization_id WHERE r.organization_id IS NULL',
            ],
            'tickets' => [
                'из заказов' => 'UPDATE tickets t JOIN orders o ON o.id = t.order_id SET t.organization_id = o.organization_id WHERE t.organization_id IS NULL',
            ],
            'ticket_scans' => [
                'из билетов' => 'UPDATE ticket_scans ts JOIN tickets t ON t.id = ts.ticket_id SET ts.organization_id = t.organization_id WHERE ts.organization_id IS NULL',
                'из сеансов' => 'UPDATE ticket_scans ts JOIN sessions s ON s.id = ts.session_id SET ts.organization_id = s.organization_id WHERE ts.organization_id IS NULL',
            ],
            'halls' => [
                'из площадок' => 'UPDATE halls h JOIN venues v ON v.id = h.venue_id SET h.organization_id = v.organization_id WHERE h.organization_id IS NULL',
            ],
            'hall_schema_versions' => [
                'из залов' => 'UPDATE hall_schema_versions hsv JOIN halls h ON h.id = hsv.hall_id SET hsv.organization_id = h.organization_id WHERE hsv.organization_id IS NULL',
            ],
            'sectors' => [
                'из версий схемы' => 'UPDATE sectors s JOIN hall_schema_versions hsv ON hsv.id = s.schema_version_id SET s.organization_id = hsv.organization_id WHERE s.organization_id IS NULL',
            ],
            'hall_rows' => [
                'из секторов' => 'UPDATE hall_rows hr JOIN sectors s ON s.id = hr.sector_id SET hr.organization_id = s.organization_id WHERE hr.organization_id IS NULL',
            ],
            'seats' => [
                'из рядов→секторов' => 'UPDATE seats st JOIN hall_rows hr ON hr.id = st.row_id JOIN sectors s ON s.id = hr.sector_id SET st.organization_id = s.organization_id WHERE st.organization_id IS NULL',
            ],
            'hall_tables' => [
                'из секторов' => 'UPDATE hall_tables ht JOIN sectors s ON s.id = ht.sector_id SET ht.organization_id = s.organization_id WHERE ht.organization_id IS NULL',
            ],
            'standing_zones' => [
                'из секторов' => 'UPDATE standing_zones sz JOIN sectors s ON s.id = sz.sector_id SET sz.organization_id = s.organization_id WHERE sz.organization_id IS NULL',
            ],
            'event_translations' => [
                'из событий' => 'UPDATE event_translations et JOIN events e ON e.id = et.event_id SET et.organization_id = e.organization_id WHERE et.organization_id IS NULL',
            ],
            'venue_translations' => [
                'из площадок' => 'UPDATE venue_translations vt JOIN venues v ON v.id = vt.venue_id SET vt.organization_id = v.organization_id WHERE vt.organization_id IS NULL',
            ],
            'page_translations' => [
                'из страниц' => 'UPDATE page_translations pt JOIN pages p ON p.id = pt.page_id SET pt.organization_id = p.organization_id WHERE pt.organization_id IS NULL',
            ],
            'promo_code_redemptions' => [
                'из промокодов' => 'UPDATE promo_code_redemptions pcr JOIN promo_codes pc ON pc.id = pcr.promo_code_id SET pcr.organization_id = pc.organization_id WHERE pcr.organization_id IS NULL',
            ],
            'event_dates' => [
                'из событий' => 'UPDATE event_dates ed JOIN events e ON e.id = ed.event_id SET ed.organization_id = e.organization_id WHERE ed.organization_id IS NULL',
            ],
            'event_speakers' => [
                'из событий' => 'UPDATE event_speakers es JOIN events e ON e.id = es.event_id SET es.organization_id = e.organization_id WHERE es.organization_id IS NULL',
            ],
            'event_sponsors' => [
                'из событий' => 'UPDATE event_sponsors es JOIN events e ON e.id = es.event_id SET es.organization_id = e.organization_id WHERE es.organization_id IS NULL',
            ],
            'event_faqs' => [
                'из событий' => 'UPDATE event_faqs ef JOIN events e ON e.id = ef.event_id SET ef.organization_id = e.organization_id WHERE ef.organization_id IS NULL',
            ],
            'event_artists' => [
                'из событий' => 'UPDATE event_artists ea JOIN events e ON e.id = ea.event_id SET ea.organization_id = e.organization_id WHERE ea.organization_id IS NULL',
            ],
            'event_schedule_items' => [
                'из событий' => 'UPDATE event_schedule_items esi JOIN events e ON e.id = esi.event_id SET esi.organization_id = e.organization_id WHERE esi.organization_id IS NULL',
            ],
            'artist_event' => [
                'из событий' => 'UPDATE artist_event ae JOIN events e ON e.id = ae.event_id SET ae.organization_id = e.organization_id WHERE ae.organization_id IS NULL',
            ],
            'ab_variants' => [
                'из экспериментов' => 'UPDATE ab_variants av JOIN ab_experiments ae ON ae.id = av.experiment_id SET av.organization_id = ae.organization_id WHERE av.organization_id IS NULL',
            ],
            'ab_assignments' => [
                'из экспериментов' => 'UPDATE ab_assignments aa JOIN ab_experiments ae ON ae.id = aa.experiment_id SET aa.organization_id = ae.organization_id WHERE aa.organization_id IS NULL',
            ],
            'ab_metrics' => [
                'из экспериментов' => 'UPDATE ab_metrics am JOIN ab_experiments ae ON ae.id = am.experiment_id SET am.organization_id = ae.organization_id WHERE am.organization_id IS NULL',
            ],
            'webhook_deliveries' => [
                'из вебхуков' => 'UPDATE webhook_deliveries wd JOIN webhooks w ON w.id = wd.webhook_id SET wd.organization_id = w.organization_id WHERE wd.organization_id IS NULL',
            ],
            'event_favorites' => [
                'из событий' => 'UPDATE event_favorites ef JOIN events e ON e.id = ef.event_id SET ef.organization_id = e.organization_id WHERE ef.organization_id IS NULL',
            ],
            'event_follows' => [
                'из событий' => 'UPDATE event_follows ef JOIN events e ON e.id = ef.event_id SET ef.organization_id = e.organization_id WHERE ef.organization_id IS NULL',
            ],
            'follow_notifications' => [
                'из событий' => 'UPDATE follow_notifications fn JOIN events e ON e.id = fn.event_id SET fn.organization_id = e.organization_id WHERE fn.organization_id IS NULL',
            ],
            'raffles' => [
                'из записей розыгрыша' => 'UPDATE raffles r JOIN (SELECT re.raffle_id, MIN(o.organization_id) AS org_id FROM raffle_entries re JOIN orders o ON o.id = re.order_id GROUP BY re.raffle_id HAVING COUNT(DISTINCT o.organization_id) = 1) x ON x.raffle_id = r.id SET r.organization_id = x.org_id WHERE r.organization_id IS NULL',
            ],
            'raffle_prizes' => [
                'из розыгрышей' => 'UPDATE raffle_prizes rp JOIN raffles r ON r.id = rp.raffle_id SET rp.organization_id = r.organization_id WHERE rp.organization_id IS NULL',
            ],
            'raffle_entries' => [
                'из заказов' => 'UPDATE raffle_entries re JOIN orders o ON o.id = re.order_id SET re.organization_id = o.organization_id WHERE re.organization_id IS NULL',
                'из розыгрышей' => 'UPDATE raffle_entries re JOIN raffles r ON r.id = re.raffle_id SET re.organization_id = r.organization_id WHERE re.organization_id IS NULL',
            ],
            'raffle_winners' => [
                'из розыгрышей' => 'UPDATE raffle_winners rw JOIN raffles r ON r.id = rw.raffle_id SET rw.organization_id = r.organization_id WHERE rw.organization_id IS NULL',
            ],
        ];
    }

    /**
     * Выполнить один шаг: до-счёт NULL, UPDATE (или dry-run с откатом),
     * после-счёт и вывод.
     */
    private function runStep(string $table, string $label, string $sql, bool $dryRun): void
    {
        $before = DB::table($table)->whereNull('organization_id')->count();

        if ($dryRun) {
            DB::beginTransaction();

            try {
                $affected = DB::update($sql);
            } finally {
                DB::rollBack();
            }
        } else {
            $affected = DB::update($sql);
        }

        $after = DB::table($table)->whereNull('organization_id')->count();

        $this->line(sprintf(
            '  %-22s · %-28s заполнено: %d, осталось NULL: %d',
            $table,
            $label,
            $affected,
            $after,
        ));

        if ($after > 0 && $before === $after) {
            $this->warn(sprintf(
                '    ⚠ %s: %d строк(и) нельзя отнести к организации — нужен ручной разбор.',
                $table,
                $after,
            ));
        }
    }
}
