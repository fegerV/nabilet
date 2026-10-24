<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Loyalty\Models\EventFollow;
use Nabilet\Modules\Loyalty\Models\FollowNotification;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Идемпотентность подписных писем — `follow_notifications`.
 *
 * ПОЧЕМУ ЭТО ОТДЕЛЬНЫЙ ТЕСТ, А НЕ ЧАСТЬ ТЕСТА API
 *   Здесь проверяется не HTTP-контракт, а инвариант СХЕМЫ: уникальный ключ
 *   `uq_follow_notifications_dedupe (follow_id, session_id, template_code)`.
 *   От него зависит единственное свойство рассылки, которое нельзя проверить
 *   юнит-тестом без базы, — что одно письмо не уйдёт дважды, даже если два
 *   процесса (крон и ручной запуск, или два тика внахлёст) взяли одну и ту же
 *   пару «подписка × сеанс» одновременно.
 *
 *   SELECT-then-INSERT здесь недостаточно в принципе: между проверкой и
 *   вставкой влезает второй процесс. Захват права на письмо делает `claim()`,
 *   который опирается на ключ, а не на собственный SELECT.
 *
 * CHECK на `status` проверяется тоже: словарь `queued|sent|failed` взят у
 * `notifications.status`, и строка с чужим состоянием не должна записаться
 * даже в обход приложения.
 */
class FollowNotificationIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const TEMPLATE = 'follow.new_session';

    /** @return array{0: EventFollow, 1: int} подписка и внутренний id сеанса */
    private function seedFollowWithSession(): array
    {
        [, $eventId, $sessionId] = $this->seedSellableSeat(available: 5);

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'subscriber@example.com',
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $follow = EventFollow::remember($userId, $eventId);

        return [$follow, $sessionId];
    }

    public function test_second_claim_is_null(): void
    {
        [$follow, $sessionId] = $this->seedFollowWithSession();

        $first = FollowNotification::claim(
            $follow->id,
            $follow->user_id,
            $follow->event_id,
            $sessionId,
            self::TEMPLATE,
        );

        $this->assertInstanceOf(FollowNotification::class, $first);
        $this->assertSame(FollowNotification::STATUS_QUEUED, $first->status);
        $this->assertNull($first->sent_at, 'строка создаётся ДО отправки — отправка ещё не подтверждена');

        $second = FollowNotification::claim(
            $follow->id,
            $follow->user_id,
            $follow->event_id,
            $sessionId,
            self::TEMPLATE,
        );

        $this->assertNull($second, 'второй процесс получил отказ: по этой тройке письмо уже рассылают');
        $this->assertSame(1, DB::table('follow_notifications')->count());
    }

    public function test_another_template_for_the_same_session_is_a_new_letter(): void
    {
        [$follow, $sessionId] = $this->seedFollowWithSession();

        FollowNotification::claim($follow->id, $follow->user_id, $follow->event_id, $sessionId, self::TEMPLATE);
        FollowNotification::claim($follow->id, $follow->user_id, $follow->event_id, $sessionId, 'follow.last_seats');

        $this->assertSame(2, DB::table('follow_notifications')->count());
    }

    public function test_the_database_refuses_a_duplicate_even_without_the_model(): void
    {
        [$follow, $sessionId] = $this->seedFollowWithSession();

        $row = [
            'public_id' => (string) Str::ulid()->toBase32(),
            'follow_id' => $follow->id,
            'user_id' => $follow->user_id,
            'event_id' => $follow->event_id,
            'session_id' => $sessionId,
            'template_code' => self::TEMPLATE,
            'status' => FollowNotification::STATUS_QUEUED,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        DB::table('follow_notifications')->insert($row);
        $row['public_id'] = (string) Str::ulid()->toBase32();

        // Инвариант держит база, а не приложение: приложение может ошибиться,
        // сломаться или быть откатано — ключ останется.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('follow_notifications')->insert($row);
    }

    public function test_status_only_accepts_the_notifications_vocabulary(): void
    {
        [$follow, $sessionId] = $this->seedFollowWithSession();

        // Словарь заперт `ck_follow_notifications_status`, и строка с чужим
        // состоянием обязана упасть на вставке — даже если её пишет не модель,
        // а прямой SQL. Ошибка 3819, а не тихое «записалось непонятно что».
        $this->expectException(\Illuminate\Database\QueryException::class);
        // Сравниваем по имени ограничения, а не по полному тексту MySQL:
        // формулировка («General error: 3819 …») — дело сервера и может
        // меняться от версии, имя же — наш контракт.
        $this->expectExceptionMessage('ck_follow_notifications_status');

        DB::table('follow_notifications')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'follow_id' => $follow->id,
            'user_id' => $follow->user_id,
            'event_id' => $follow->event_id,
            'session_id' => $sessionId,
            'template_code' => self::TEMPLATE,
            'status' => 'sent_by_accident',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_sent_and_failed_are_recorded_on_the_same_row(): void
    {
        [$follow, $sessionId] = $this->seedFollowWithSession();

        $row = FollowNotification::claim($follow->id, $follow->user_id, $follow->event_id, $sessionId, self::TEMPLATE);
        $this->assertNotNull($row);

        $notificationId = DB::table('notifications')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'user_id' => $follow->user_id,
            'channel' => 'mail',
            'type' => self::TEMPLATE,
            'recipient' => 'subscriber@example.com',
            'status' => 'sent',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $row->markSent($notificationId);

        $row->refresh();

        $this->assertSame(FollowNotification::STATUS_SENT, $row->status);
        $this->assertSame((int) $notificationId, (int) $row->notification_id);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->error_message);

        $row->markFailed('SMTP отказал: 421');
        $row->refresh();

        $this->assertSame(FollowNotification::STATUS_FAILED, $row->status);
        $this->assertSame('SMTP отказал: 421', $row->error_message);
        // Отказ транспорта не отменяет факта отправки: `sent_at` уже стоит.
        $this->assertNotNull($row->sent_at);
    }

    public function test_deleting_the_follow_removes_its_journal(): void
    {
        [$follow, $sessionId] = $this->seedFollowWithSession();

        FollowNotification::claim($follow->id, $follow->user_id, $follow->event_id, $sessionId, self::TEMPLATE);
        $this->assertSame(1, DB::table('follow_notifications')->count());

        // Отписка удаляет строку подписки — и её журнал уходит с ней
        // (`fk_follow_notifications_follow ON DELETE CASCADE`). Иначе таблица
        // копила бы записи о письмах, которые больше некому адресовать.
        EventFollow::query()->whereKey($follow->id)->delete();

        $this->assertSame(0, DB::table('follow_notifications')->count());
    }

    // ── поведение захвата при повторе ──────────────────────────────────────

    public function test_sent_row_is_never_taken_over(): void
    {
        [$follow, $sessionId] = $this->seedFollowWithSession();

        $notificationId = DB::table('notifications')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'user_id' => $follow->user_id,
            'channel' => 'mail',
            'type' => self::TEMPLATE,
            'recipient' => 'subscriber@example.com',
            'status' => 'sent',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $row = FollowNotification::claim($follow->id, $follow->user_id, $follow->event_id, $sessionId, self::TEMPLATE);
        $this->assertNotNull($row);
        $row->markSent($notificationId);

        // Письмо ушло — «повторить» нечего даже в теории. Повтор джобы через
        // сутки ничего не сделает, и это главный смысл журнала.
        $this->assertNull(FollowNotification::claim(
            $follow->id,
            $follow->user_id,
            $follow->event_id,
            $sessionId,
            self::TEMPLATE,
        ));
    }

    public function test_failed_row_is_handed_over_to_the_retry(): void
    {
        [$follow, $sessionId] = $this->seedFollowWithSession();

        $row = FollowNotification::claim($follow->id, $follow->user_id, $follow->event_id, $sessionId, self::TEMPLATE);
        $this->assertNotNull($row);
        $row->markFailed('SMTP отказал: 421');

        // Транспорт сказал «нет» — письмо не ушло, и повтор джобы обязан его
        // догнать. Захват возвращает ту же строку: второго письма о том же
        // поводе не будет, а это — доберётся.
        $retry = FollowNotification::claim(
            $follow->id,
            $follow->user_id,
            $follow->event_id,
            $sessionId,
            self::TEMPLATE,
        );

        $this->assertInstanceOf(FollowNotification::class, $retry);
        $this->assertSame($row->id, $retry->id);
        $this->assertSame(FollowNotification::STATUS_QUEUED, $retry->status);
        $this->assertSame(1, DB::table('follow_notifications')->count());
    }

    public function test_fresh_queued_row_belongs_to_a_live_attempt(): void
    {
        [$follow, $sessionId] = $this->seedFollowWithSession();

        FollowNotification::claim($follow->id, $follow->user_id, $follow->event_id, $sessionId, self::TEMPLATE);

        // `queued`, который только что создан: его держит живая попытка. Два
        // `updated()` подряд ставят две джобы, и без этой защиты вторая отправила
        // бы письмо повторно — ровно ту жалобу «письмо пришло дважды», для
        // разбора которой журнал и ведут.
        $this->assertNull(FollowNotification::claim(
            $follow->id,
            $follow->user_id,
            $follow->event_id,
            $sessionId,
            self::TEMPLATE,
        ));
    }

    public function test_stale_queued_row_is_recovered_after_its_owner_died(): void
    {
        [$follow, $sessionId] = $this->seedFollowWithSession();

        FollowNotification::claim($follow->id, $follow->user_id, $follow->event_id, $sessionId, self::TEMPLATE);

        // Воркера убили между захватом и отметкой об отправке. Очередь вернёт
        // джобу только через `retry_after`, то есть много позже аренды, — и
        // строка к тому времени считается ничьей.
        DB::table('follow_notifications')->update([
            'status' => FollowNotification::STATUS_QUEUED,
            'updated_at' => now()->subMinutes(10)->toDateTimeString(),
        ]);

        $recovered = FollowNotification::claim(
            $follow->id,
            $follow->user_id,
            $follow->event_id,
            $sessionId,
            self::TEMPLATE,
        );

        $this->assertInstanceOf(FollowNotification::class, $recovered);
        $this->assertSame(FollowNotification::STATUS_QUEUED, $recovered->status);
        $this->assertSame(1, DB::table('follow_notifications')->count());
    }
}
