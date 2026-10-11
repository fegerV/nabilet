<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Services\EventService;
use Nabilet\Modules\Events\StateMachines\EventStateMachine;

/**
 * Автоматическое завершение прошедших событий (SEO-аудит P-06).
 *
 * ДЕФЕКТ. Статус `completed` ставился только вручную: задачи планировщика,
 * которая переводила бы событие с прошедшими сеансами, не существовало
 * (`routes/console.php` её не знал). Прошедший концерт оставался `published`, а
 * `published` для разметки означает «предстоит»:
 *
 *   - `StructuredDataService::getEventStatus()` отдаёт `EventScheduled`;
 *   - `availability` считается по инвентарю и остаётся `InStock`;
 *   - rich-результат Google показывает прошедшую дату как будущую.
 *
 * Разметка, расходящаяся с реальностью, — это не «небольшая неточность»: Google
 * понижает доверие к ней и в итоге перестаёт показывать расширенный результат
 * вовсе. Плюс страница события продолжает выглядеть как «витрина покупки» для
 * мероприятия, на которое билет уже не купить.
 *
 * ЧТО ДЕЛАЕТ КОМАНДА. Переводит `published → completed` у событий, у которых
 * ЕСТЬ хотя бы один сеанс и ВСЕ сеансы уже закончились. Переход идёт через
 * `EventService::complete()`, который проверяет его по `EventStateMachine`, — то
 * есть запрещённый переход здесь невозможен, а не «просто не встречается».
 *
 * ПОЧЕМУ `COALESCE(ends_at, starts_at + окно)`. `sessions.ends_at` nullable:
 * администратор вправе указать только начало. Считать такой сеанс закончившимся
 * в момент начала нельзя — идущий концерт объявлялся бы прошедшим. Поэтому для
 * сеанса без `ends_at` берётся окно из
 * `nabilet.events.session_default_duration_hours`.
 *
 * Запуск — раз в час (см. `routes/console.php`).
 */
class SyncEventStatusCommand extends Command
{
    /** @var string */
    protected $signature = 'events:sync-status
                            {--limit=500 : Сколько событий обработать за проход}
                            {--dry-run : Показать, что было бы изменено, но не менять}';

    /** @var string */
    protected $description = 'Переводит прошедшие события в completed, чтобы разметка не обещала прошедшее мероприятие';

    public function handle(EventService $events): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $candidates = $this->pastEvents($limit)->get();

        if ($candidates->isEmpty()) {
            $this->info('Прошедших событий в статусе published нет.');

            return self::SUCCESS;
        }

        $completed = 0;

        foreach ($candidates as $event) {
            if ($dryRun) {
                $this->line(sprintf('  #%d %s → completed (dry-run)', $event->id, $event->slug));

                continue;
            }

            // Через сервис, а не записью статуса напрямую: `complete()` проверяет
            // переход по машине состояний. Событие могло измениться между
            // выборкой и этим моментом (админ отменил его) — тогда переход
            // запрещён, и запись молча испортила бы данные.
            $events->complete($event);

            $completed++;
        }

        $this->info($dryRun
            ? sprintf('К завершению: %d событий.', $candidates->count())
            : sprintf('Переведено в completed: %d событий.', $completed));

        return self::SUCCESS;
    }

    /**
     * Опубликованные события, у которых все сеансы уже закончились.
     *
     * Условия, каждое из которых обязательно:
     *
     *  - `status = published` — единственный статус, из которого `completed`
     *    достижим по машине состояний;
     *  - `whereHas('sessions')` — событие без сеансов не имеет даты окончания.
     *    Такие строки остаются как есть: «нет данных» — это не «прошло»;
     *  - `whereDoesntHave(..., 'ещё не закончился')` — ни один сеанс не должен
     *    быть в будущем или идти сейчас. Именно «ни один», а не «последний»:
     *    у события может быть серия сеансов, и завершать его после первого
     *    нельзя.
     *
     * @return Builder<Event>
     */
    private function pastEvents(int $limit): Builder
    {
        $graceHours = max(1, (int) config('nabilet.events.session_default_duration_hours', 3));

        // Окно подставляется в SQL как целое, а не параметром: `INTERVAL ? HOUR`
        // разбирается не всеми версиями MySQL, а значение здесь уже приведено к
        // int и ограничено снизу, поэтому подстановка безопасна.
        $effectiveEnd = "COALESCE(sessions.ends_at, DATE_ADD(sessions.starts_at, INTERVAL {$graceHours} HOUR))";

        return Event::query()
            ->where('status', EventStateMachine::PUBLISHED)
            ->whereHas('sessions')
            ->whereDoesntHave('sessions', static function (Builder $query) use ($effectiveEnd): void {
                $query->whereRaw("{$effectiveEnd} >= ?", [now()]);
            })
            ->orderBy('id')
            ->limit($limit);
    }
}
