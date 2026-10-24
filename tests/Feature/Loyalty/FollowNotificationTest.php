<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Jobs\NotifyFollowersOfNewSessionJob;
use Nabilet\Modules\Loyalty\Models\EventFollow;
use Nabilet\Modules\Loyalty\Models\FollowNotification;
use Nabilet\Modules\Loyalty\Services\FollowNotificationData;
use Nabilet\Modules\Loyalty\Support\FollowNotificationCodes;
use Nabilet\Modules\Notifications\Mail\TemplateMail;
use Nabilet\Modules\Notifications\Services\TransactionalMailService;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Sessions\StateMachines\SessionStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Подписные письма «клуб зрителей», фаза 1: новый сеанс и открытие продаж.
 *
 * ЧТО ЗДЕСЬ ЗАЩИЩАЕТСЯ
 *   1. ДОСТАВКА. Подписчик получает письмо, в письме — имя, название, дата,
 *      место, состояние продаж и ссылка на витрину. Письмо без даты не решает
 *      задачу «не пропусти», поэтому проверяется тело, а не факт отправки.
 *   2. ИДЕМПОТЕНТНОСТЬ. Повтор джобы (SMTP упал, воркер убит) не означает
 *      второе письмо: `uq_follow_notifications_dedupe` + `claim()`.
 *   3. СОГЛАСИЕ. Отписался — не получает. Нет адреса — письмо не «уходит в
 *      пустоту», а помечается в журнале с причиной.
 *   4. АКТУАЛЬНОСТЬ. Отменённый и завершённый сеансы молчат; «покупайте» не
 *      уходит, если продажи ещё закрыты. Иначе письмо звало бы людей зря.
 */
class FollowNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** Фиксированная дата сеанса: проверка тела не должна зависеть от «сейчас». */
    private const STARTS_AT = '2027-03-14 19:00:00';

    private const STARTS_AT_HUMAN = '14.03.2027 19:00';

    public function test_every_follower_gets_the_mail_once_with_the_session_facts(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        [, $eventId, $session] = $this->seedSessionFixture();
        $first = $this->follow($eventId, 'first@example.test', 'Иван', 'Петров');
        $second = $this->follow($eventId, 'second@example.test', 'Анна', 'Смирнова');

        $this->runJob($session->id, FollowNotificationCodes::NEW_SESSION);

        Mail::assertSentCount(2);
        Mail::assertSent(TemplateMail::class, function (TemplateMail $mail): bool {
            return $mail->hasTo('first@example.test')
                && $mail->subjectLine === 'Новый сеанс: Концерт «Секрет»'
                && str_contains($mail->htmlBody, 'Иван Петров')
                && str_contains($mail->htmlBody, 'Концерт «Секрет»')
                // Дата сеанса и место — то, ради чего письмо отправлено.
                && str_contains($mail->htmlBody, self::STARTS_AT_HUMAN)
                && str_contains($mail->htmlBody, 'Театр')
                && str_contains($mail->htmlBody, 'Билеты уже в продаже.')
                && str_contains($mail->htmlBody, '/#/event/');
        });
        Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail): bool => $mail->hasTo('second@example.test'));

        // Журнал: строка на подписчика, статус `sent`, ссылка на запись
        // транспорта. Без `notification_id` связь «какое письмо» восстановилась
        // бы только по совпадению адреса и времени.
        $rows = FollowNotification::query()->get();
        self::assertCount(2, $rows);
        self::assertSame(['sent', 'sent'], $rows->pluck('status')->sort()->values()->all());
        self::assertTrue($rows->every(
            fn (FollowNotification $row): bool => $row->notification_id !== null && $row->sent_at !== null
        ));
        self::assertSame(
            [$first->id, $second->id],
            $rows->pluck('follow_id')->sort()->values()->all(),
        );
    }

    public function test_running_the_job_twice_does_not_send_a_second_mail(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        [, $eventId, $session] = $this->seedSessionFixture();
        $this->follow($eventId, 'first@example.test');

        $this->runJob($session->id, FollowNotificationCodes::NEW_SESSION);
        $this->runJob($session->id, FollowNotificationCodes::NEW_SESSION);

        Mail::assertSentCount(1);
        self::assertSame(1, FollowNotification::query()->count());
    }

    public function test_follower_without_email_is_marked_failed_instead_of_sent(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        [, $eventId, $session] = $this->seedSessionFixture();

        // Аккаунт без адреса: гость, оформивший заказ без контакта, или импорт.
        $user = User::query()->create([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => null,
            'first_name' => 'Без',
            'last_name' => 'Почты',
        ]);
        EventFollow::remember($user->id, $eventId);

        $this->runJob($session->id, FollowNotificationCodes::NEW_SESSION);

        Mail::assertNothingSent();
        $row = FollowNotification::query()->firstOrFail();
        self::assertSame(FollowNotification::STATUS_FAILED, $row->status);
        self::assertStringContainsString('адреса', (string) $row->error_message);
    }

    public function test_unfollowed_user_gets_no_mail(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        [, $eventId, $session] = $this->seedSessionFixture();
        $follow = $this->follow($eventId, 'first@example.test');

        // Отписка — удаление строки. Флага «не слать» нет и быть не должно:
        // согласие существует ровно до тех пор, пока существует строка.
        $follow->delete();

        $this->runJob($session->id, FollowNotificationCodes::NEW_SESSION);

        Mail::assertNothingSent();
        self::assertSame(0, FollowNotification::query()->count());
    }

    public function test_two_reasons_produce_two_separate_mails(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        [, $eventId, $session] = $this->seedSessionFixture();
        $this->follow($eventId, 'first@example.test');

        $this->runJob($session->id, FollowNotificationCodes::NEW_SESSION);
        $this->runJob($session->id, FollowNotificationCodes::SALES_OPEN);

        // Один сеанс, два повода — два письма, и оба разные.
        Mail::assertSentCount(2);
        self::assertSame(
            [FollowNotificationCodes::NEW_SESSION, FollowNotificationCodes::SALES_OPEN],
            FollowNotification::query()->orderBy('id')->pluck('template_code')->all(),
        );
    }

    /**
     * Отменённый и завершённый сеансы: мероприятия не будет — письмо звало бы
     * человека зря.
     *
     * @return array<string, list<string>>
     */
    #[DataProvider('silentSessionStatuses')]
    public function test_sessions_that_will_not_happen_are_silent(string $status): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        [, $eventId, $session] = $this->seedSessionFixture(status: $status);
        $this->follow($eventId, 'first@example.test');

        $this->runJob($session->id, FollowNotificationCodes::NEW_SESSION);

        Mail::assertNothingSent();
        self::assertSame(0, FollowNotification::query()->count());
    }

    /**
     * @return array<string, list<string>>
     */
    public static function silentSessionStatuses(): array
    {
        return [
            'cancelled' => [SessionStateMachine::CANCELLED],
            'completed' => [SessionStateMachine::COMPLETED],
        ];
    }

    public function test_sales_open_mail_is_not_sent_when_the_window_has_not_opened(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        // Статус `on_sale`, но окно продаж наступит через три дня: «покупайте»
        // сейчас было бы ложью. `isSellableAt()` — тот же гейт, что продаёт
        // билеты, поэтому письмо ждёт настоящего открытия.
        [, $eventId, $session] = $this->seedSessionFixture(salesStartAt: now()->addDays(3));
        $this->follow($eventId, 'first@example.test');

        $this->runJob($session->id, FollowNotificationCodes::SALES_OPEN);

        Mail::assertNothingSent();
        self::assertSame(0, FollowNotification::query()->count());
    }

    public function test_new_session_mail_still_announces_a_session_without_sales(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        [, $eventId, $session] = $this->seedSessionFixture(
            status: SessionStateMachine::SCHEDULED,
            salesStartAt: now()->addDay(),
        );
        $this->follow($eventId, 'first@example.test');

        $this->runJob($session->id, FollowNotificationCodes::NEW_SESSION);

        // Дата объявляется всегда, продажи — когда будут. Иначе заранее
        // созданный сеанс не о чём было бы сообщать.
        Mail::assertSent(TemplateMail::class, function (TemplateMail $mail): bool {
            return str_contains($mail->htmlBody, 'Продажи откроются')
                && ! str_contains($mail->htmlBody, 'Билеты уже в продаже.');
        });
    }

    public function test_unknown_template_code_is_ignored(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        [, $eventId, $session] = $this->seedSessionFixture();
        $this->follow($eventId, 'first@example.test');

        $this->runJob($session->id, 'follow.does_not_exist');

        Mail::assertNothingSent();
        self::assertSame(0, FollowNotification::query()->count());
    }

    // ── обсервер ───────────────────────────────────────────────────────────

    public function test_observer_dispatches_on_creation_and_on_opening_sales(): void
    {
        Bus::fake();

        [, , $session] = $this->seedSessionFixture(
            status: SessionStateMachine::SCHEDULED,
            viaModel: true,
        );

        // Сеанс создан моделью — это и есть тот путь, которым сеансы появляются
        // в приложении (админка, сидер, импорт).
        Bus::assertDispatched(NotifyFollowersOfNewSessionJob::class, function (NotifyFollowersOfNewSessionJob $job) use ($session): bool {
            return $job->sessionId === $session->id
                && $job->templateCode === FollowNotificationCodes::NEW_SESSION
                && $job->afterCommit === true;
        });

        // Правка расписания — не повод для письма: подписчику неинтересно, что
        // организатор поправил таймзону.
        $session->timezone = 'Europe/Moscow';
        $session->save();

        Bus::assertNotDispatched(NotifyFollowersOfNewSessionJob::class, fn (NotifyFollowersOfNewSessionJob $job): bool =>
            $job->templateCode === FollowNotificationCodes::SALES_OPEN
        );

        // Открытие продаж переключателем — второй повод, уже другой шаблон.
        $session->status = SessionStateMachine::ON_SALE;
        $session->save();

        Bus::assertDispatched(NotifyFollowersOfNewSessionJob::class, function (NotifyFollowersOfNewSessionJob $job) use ($session): bool {
            return $job->sessionId === $session->id
                && $job->templateCode === FollowNotificationCodes::SALES_OPEN
                && $job->afterCommit === true;
        });

        // И правка окна продаж, когда переключатель уже включён: дату сдвинули
        // «на сейчас» — продажи открылись, и об этом надо сказать.
        $session->sales_start_at = now()->subMinute();
        $session->save();

        Bus::assertDispatched(NotifyFollowersOfNewSessionJob::class, function (NotifyFollowersOfNewSessionJob $job) use ($session): bool {
            return $job->sessionId === $session->id
                && $job->templateCode === FollowNotificationCodes::SALES_OPEN;
        });
    }

    /**
     * Почтовый фикстур: организация → мероприятие → зал → сеанс.
     *
     * Цепочка обязательна целиком: `sessions.venue_id`, `hall_id` и
     * `schema_version_id` — NOT NULL, и подставить в них «пустышку» нельзя, FK
     * проверит.
     *
     * @return array{0: int, 1: int, 2: Session}
     */
    private function seedSessionFixture(
        string $status = SessionStateMachine::ON_SALE,
        ?\DateTimeInterface $salesStartAt = null,
        bool $viaModel = false,
    ): array {
        $now = now()->toDateTimeString();

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $eventId = (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => 'Концерт «Секрет»',
            'slug' => 'koncert-' . Str::random(6),
            'status' => 'published',
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $venueId = (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'Театр',
            'slug' => 'venue-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $hallId = (int) DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $venueId,
            'name' => 'Большой зал',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $schemaVersionId = (int) DB::table('hall_schema_versions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'hall_id' => $hallId,
            'version' => 1,
            'status' => 'published',
            'schema_json' => json_encode(['rows' => []]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $attributes = [
            'event_id' => $eventId,
            'venue_id' => $venueId,
            'hall_id' => $hallId,
            'schema_version_id' => $schemaVersionId,
            'starts_at' => self::STARTS_AT,
            'sales_start_at' => $salesStartAt?->toDateTimeString(),
            'timezone' => 'UTC',
            'status' => $status,
        ];

        if ($viaModel) {
            // Моделью — когда обсервер обязан сработать (тест на него).
            $session = Session::query()->create($attributes);

            return [$organizationId, $eventId, $session];
        }

        // Сырой INSERT — когда сеанс нужен как данные: событий модели нет,
        // и тест письма не зависит от того, повесился ли обсервер.
        $sessionId = (int) DB::table('sessions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
        ] + $attributes + [
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$organizationId, $eventId, Session::query()->findOrFail($sessionId)];
    }

    private function follow(
        int $eventId,
        string $email,
        ?string $firstName = null,
        ?string $lastName = null,
    ): EventFollow {
        $user = User::query()->create([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        return EventFollow::remember($user->id, $eventId);
    }

    private function runJob(int $sessionId, string $templateCode): void
    {
        (new NotifyFollowersOfNewSessionJob($sessionId, $templateCode))->handle(
            app(TransactionalMailService::class),
            app(FollowNotificationData::class),
        );
    }
}
