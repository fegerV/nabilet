<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Modules\Events\Services\EventService;
use Nabilet\Modules\Events\StateMachines\EventStateMachine;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Автоматическое завершение прошедших событий (SEO-аудит P-06).
 *
 * ДЕФЕКТ. `completed` ставился только вручную — задачи планировщика,
 * переводящей событие с прошедшими сеансами, не существовало. Прошедший концерт
 * оставался `published`, а для разметки `published` значит «предстоит»:
 * `eventStatus: EventScheduled` и `availability: InStock` для мероприятия,
 * которое уже состоялось. Google понижает доверие к разметке, расходящейся с
 * реальностью, и в итоге перестаёт показывать расширенный результат.
 *
 * ЧТО ПРОВЕРЯЕТСЯ. Команда `events:sync-status` завершает событие ровно тогда,
 * когда это правда: есть сеансы и ВСЕ они закончились. Серия сеансов, сеанс без
 * `ends_at` и событие без сеансов — отдельные случаи, на каждом из которых
 * наивная реализация («если `starts_at < now`) ошибается.
 */
class EventStatusSyncTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    /** Все сеансы в прошлом — событие завершается. */
    public function test_an_event_with_only_past_sessions_becomes_completed(): void
    {
        $eventId = $this->publishedEventWithSessions([
            ['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHours(3)],
        ]);

        $this->artisan('events:sync-status')->assertExitCode(0);

        $this->assertSame(EventStateMachine::COMPLETED, $this->statusOf($eventId));
    }

    /** Сеанс в будущем — событие остаётся опубликованным. */
    public function test_an_event_with_a_future_session_stays_published(): void
    {
        $eventId = $this->publishedEventWithSessions([
            ['starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(3)],
        ]);

        $this->artisan('events:sync-status')->assertExitCode(0);

        $this->assertSame(EventStateMachine::PUBLISHED, $this->statusOf($eventId));
    }

    /**
     * Серия сеансов: один прошёл, другой впереди — событие НЕ завершается.
     *
     * Это главный случай, на котором ошибается наивная реализация. Если
     * смотреть только на ПЕРВЫЙ сеанс, событие объявлялось бы прошедшим сразу
     * после первого представления, хотя билеты на следующие ещё продаются.
     */
    public function test_an_event_with_a_remaining_session_is_not_completed(): void
    {
        $eventId = $this->publishedEventWithSessions([
            ['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHours(3)],
            ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(3)],
        ]);

        $this->artisan('events:sync-status')->assertExitCode(0);

        $this->assertSame(EventStateMachine::PUBLISHED, $this->statusOf($eventId));
    }

    /** Все сеансы серии прошли — событие завершается. */
    public function test_an_event_whose_whole_series_is_past_becomes_completed(): void
    {
        $eventId = $this->publishedEventWithSessions([
            ['starts_at' => now()->subDays(5), 'ends_at' => now()->subDays(5)->addHours(3)],
            ['starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addHours(3)],
        ]);

        $this->artisan('events:sync-status')->assertExitCode(0);

        $this->assertSame(EventStateMachine::COMPLETED, $this->statusOf($eventId));
    }

    /**
     * Сеанс без `ends_at`, начавшийся давно, — считается прошедшим.
     *
     * `sessions.ends_at` nullable: администратор вправе указать только начало.
     * Без окна по умолчанию такие сеансы не завершались бы НИКОГДА, и событие
     * навсегда оставалось бы «запланированным» — то есть фикс P-06 не работал бы
     * на реальных данных, где `ends_at` часто пуст.
     */
    public function test_a_session_without_an_end_time_is_completed_after_the_default_window(): void
    {
        $eventId = $this->publishedEventWithSessions([
            ['starts_at' => now()->subDays(2), 'ends_at' => null],
        ]);

        $this->artisan('events:sync-status')->assertExitCode(0);

        $this->assertSame(EventStateMachine::COMPLETED, $this->statusOf($eventId));
    }

    /**
     * Сеанс без `ends_at`, который только что начался, НЕ считается прошедшим.
     *
     * Идущий концерт нельзя объявлять состоявшимся: страница показала бы
     * «прошло», пока зал ещё полон. Именно для этого окно по умолчанию, а не
     * `COALESCE(ends_at, starts_at)`.
     */
    public function test_a_session_without_an_end_time_that_is_still_running_is_not_completed(): void
    {
        $eventId = $this->publishedEventWithSessions([
            ['starts_at' => now()->subMinutes(30), 'ends_at' => null],
        ]);

        $this->artisan('events:sync-status')->assertExitCode(0);

        $this->assertSame(EventStateMachine::PUBLISHED, $this->statusOf($eventId));
    }

    /**
     * Событие без сеансов не завершается.
     *
     * Даты окончания нет — значит «прошло» неизвестно. Завершать по отсутствию
     * данных нельзя: событие-черновик с будущими сеансами, у которого сеансы
     * ещё не заведены, исчезло бы из афиши.
     */
    public function test_an_event_without_sessions_is_left_alone(): void
    {
        $eventId = $this->publishedEventWithSessions([]);

        $this->artisan('events:sync-status')->assertExitCode(0);

        $this->assertSame(EventStateMachine::PUBLISHED, $this->statusOf($eventId));
    }

    /** Черновик с прошедшими сеансами не трогается: он и так не публичен. */
    public function test_a_draft_event_is_not_touched(): void
    {
        $eventId = $this->publishedEventWithSessions([
            ['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHours(3)],
        ]);

        DB::table('events')->where('id', $eventId)->update(['status' => EventStateMachine::DRAFT]);

        $this->artisan('events:sync-status')->assertExitCode(0);

        $this->assertSame(EventStateMachine::DRAFT, $this->statusOf($eventId));
    }

    /** `--dry-run` показывает кандидатов и ничего не меняет. */
    public function test_the_dry_run_changes_nothing(): void
    {
        $eventId = $this->publishedEventWithSessions([
            ['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHours(3)],
        ]);

        $this->artisan('events:sync-status', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(EventStateMachine::PUBLISHED, $this->statusOf($eventId));
    }

    /** Повторный проход не находит уже завершённых событий. */
    public function test_the_command_is_idempotent(): void
    {
        $eventId = $this->publishedEventWithSessions([
            ['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHours(3)],
        ]);

        $this->artisan('events:sync-status')->assertExitCode(0);
        $this->artisan('events:sync-status')
            ->expectsOutputToContain('Прошедших событий в статусе published нет.')
            ->assertExitCode(0);

        $this->assertSame(EventStateMachine::COMPLETED, $this->statusOf($eventId));
    }

    /**
     * `EventService::complete()` отказывает на переходе, который запрещён машиной.
     *
     * Команда вызывает именно сервис, поэтому проверка перехода обязана жить в
     * нём, а не в вызывающем коде. Без неё вызов на черновике записал бы
     * `completed` — статус, входящий в `EventStatus::publiclyVisible()`, то есть
     * черновик мгновенно стал бы публичным и попал бы в карту сайта.
     */
    public function test_the_service_refuses_a_forbidden_transition(): void
    {
        $eventId = $this->publishedEventWithSessions([]);

        DB::table('events')->where('id', $eventId)->update(['status' => EventStateMachine::DRAFT]);

        $event = \Nabilet\Modules\Events\Models\Event::query()->findOrFail($eventId);

        try {
            $this->app->make(EventService::class)->complete($event);

            $this->fail('черновик был переведён в completed');
        } catch (ConflictError $e) {
            $this->assertSame('EVENT_TRANSITION_NOT_ALLOWED', $e->errorCode);
        }

        $this->assertSame(EventStateMachine::DRAFT, $this->statusOf($eventId));
    }

    // ------------------------------------------------------------------
    // Вспомогательное
    // ------------------------------------------------------------------

    /**
     * Событие в статусе `published` с заданными сеансами.
     *
     * `seedSellableSeat()` создаёт всю цепочку внешних ключей и один сеанс;
     * его время переписывается, а дополнительные сеансы добавляются.
     *
     * @param  list<array{starts_at: \DateTimeInterface, ends_at: \DateTimeInterface|null}>  $sessions
     */
    private function publishedEventWithSessions(array $sessions): int
    {
        [, $eventId, $sessionId] = $this->seedSellableSeat(available: 10);

        if ($sessions === []) {
            DB::table('sessions')->where('id', $sessionId)->delete();

            return $eventId;
        }

        $template = (array) DB::table('sessions')->where('id', $sessionId)->first();

        DB::table('sessions')->where('id', $sessionId)->update([
            'starts_at' => $sessions[0]['starts_at'],
            'ends_at' => $sessions[0]['ends_at'],
        ]);

        foreach (array_slice($sessions, 1) as $session) {
            $extra = $template;
            unset($extra['id']);
            $extra['public_id'] = (string) \Illuminate\Support\Str::ulid()->toBase32();
            $extra['starts_at'] = $session['starts_at'];
            $extra['ends_at'] = $session['ends_at'];

            DB::table('sessions')->insert($extra);
        }

        return $eventId;
    }

    private function statusOf(int $eventId): string
    {
        return (string) DB::table('events')->where('id', $eventId)->value('status');
    }
}
