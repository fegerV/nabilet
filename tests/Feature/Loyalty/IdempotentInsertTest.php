<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Loyalty\Models\BonusAccount;
use Nabilet\Modules\Loyalty\Models\Referral;
use Nabilet\Modules\Loyalty\Models\ReferralCode;
use Tests\TestCase;

/**
 * «Повтор — тот же факт»: как это делает фреймворк, а не свой `try/catch`.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ТЕСТ. В шести местах стоял ручной
 * `try { create } catch (UniqueConstraintViolationException) { переспросить }` —
 * то есть переписанный `Builder::firstOrCreate()` / `createOrFirst()`. Замена
 * на фреймворковый метод меняет не только форму, но и опору: теперь поведение
 * при повторе определяется версией Laravel, а не нашим кодом. Если когда-нибудь
 * `createOrFirst()` перестанет ловить нарушение ключа (или мы откатимся на
 * версию, где его нет), весь набор «идемпотентных» операций сломается молча —
 * повтор начнёт отдавать 500 вместо того же факта.
 *
 * Поэтому здесь проверяется не «метод вернул строку», а ОПОРА:
 *   - второй вызов возвращает ТУ ЖЕ строку (а не вторую);
 *   - `wasRecentlyCreated` отличает первый вызов от повтора — на нём держатся
 *     `RaffleEntry::remember()` (null на повторе) и журнал бонусов (не двигать
 *     баланс повторно);
 *   - `createOrFirst()` действительно ловит конфликт, хотя SELECT перед вставкой
 *     не делает: второй вызов идёт прямо в INSERT и упирается в уникальный ключ.
 *
 * Тесты моделей (`RaffleEntry`, `FollowNotification`) сюда не продублированы:
 * у них тяжёлые фикстуры (заказ, сеанс, подписка) и уже есть свои наборы —
 * `RaffleTest`, `FollowNotificationIdempotencyTest`. Здесь — три модели, которым
 * хватает пользователя, и общая проба семантики.
 */
class IdempotentInsertTest extends TestCase
{
    use RefreshDatabase;

    // ── опора: `createOrFirst()` ловит конфликт и говорит, кто вставил ─────

    public function test_create_or_first_returns_the_existing_row_and_reports_it(): void
    {
        $userId = $this->seedUser();

        $first = BonusAccount::query()->createOrFirst(
            ['user_id' => $userId],
            ['balance' => 0, 'currency' => 'RUB'],
        );

        $this->assertTrue($first->wasRecentlyCreated, 'первая вставка обязана быть «своей»');

        // `createOrFirst()` не делает SELECT: этот вызов идёт прямо в INSERT,
        // упирается в `uq_bonus_accounts_user`, ловится внутри метода и
        // переспрашивает по тем же атрибутам.
        $second = BonusAccount::query()->createOrFirst(
            ['user_id' => $userId],
            ['balance' => 0, 'currency' => 'RUB'],
        );

        $this->assertFalse($second->wasRecentlyCreated, 'повтор не должен выглядеть как вставка');
        $this->assertSame($first->getKey(), $second->getKey(), 'повтор обязан вернуть ту же строку');
        $this->assertSame(
            1,
            DB::table('bonus_accounts')->where('user_id', $userId)->count(),
            'вторая строка счёта — это раздвоенный баланс',
        );
    }

    // ── счёт покупателя ────────────────────────────────────────────────────

    public function test_the_bonus_account_is_created_once_per_user(): void
    {
        $userId = $this->seedUser();

        $first = BonusAccount::forUserOrCreate($userId);
        $second = BonusAccount::forUserOrCreate($userId);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, DB::table('bonus_accounts')->where('user_id', $userId)->count());
        $this->assertSame(0, (int) $second->balance);
        $this->assertSame('RUB', (string) $second->currency);
    }

    /**
     * Валюта первого обращения фиксируется: счёт не переписывается под вторую.
     *
     * Это не придирка — `forUserOrCreate($userId, $currency)` зовут из начисления,
     * списания и возврата, и каждый передаёт валюту заказа. Второй вызов с
     * другой валютой НЕ должен молча переписать счёт: баланс уже в первой.
     */
    public function test_the_account_keeps_the_currency_it_was_opened_with(): void
    {
        $userId = $this->seedUser();

        BonusAccount::forUserOrCreate($userId, 'RUB');
        $again = BonusAccount::forUserOrCreate($userId, 'KZT');

        $this->assertSame('RUB', (string) $again->currency);
    }

    // ── код приглашения ────────────────────────────────────────────────────

    public function test_the_referral_code_is_created_once_per_user(): void
    {
        $userId = $this->seedUser();

        $first = ReferralCode::forUserOrCreate($userId);
        $second = ReferralCode::forUserOrCreate($userId);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame((string) $first->code, (string) $second->code, 'код не должен меняться от повторного захода');
        $this->assertSame(1, DB::table('referral_codes')->where('user_id', $userId)->count());
    }

    // ── приглашение по адресу ──────────────────────────────────────────────

    public function test_the_same_invitation_is_one_row_and_a_later_account_refines_it(): void
    {
        $inviterId = $this->seedUser('inviter@example.com');
        $invitedId = $this->seedUser('friend@example.com');

        // Переход по ссылке до регистрации друга: аккаунта ещё нет.
        $pending = Referral::upsertByEmail($inviterId, 'Friend@Example.com');

        $this->assertSame(Referral::STATUS_PENDING, (string) $pending->status);
        $this->assertNull($pending->invited_user_id);
        $this->assertSame('friend@example.com', (string) $pending->invited_email, 'адрес хранится в нижнем регистре');

        // Повторный переход — то же приглашение, а не второе.
        $again = Referral::upsertByEmail($inviterId, 'friend@example.com');

        $this->assertSame($pending->getKey(), $again->getKey());
        $this->assertSame(1, DB::table('referrals')->where('inviter_user_id', $inviterId)->count());

        // Друг зарегистрировался: та же строка уточняется, статус растёт.
        $refined = Referral::upsertByEmail($inviterId, 'friend@example.com', $invitedId);

        $this->assertSame($pending->getKey(), $refined->getKey());
        $this->assertSame($invitedId, (int) $refined->invited_user_id);
        $this->assertSame(Referral::STATUS_REGISTERED, (string) $refined->status);
        $this->assertSame(1, DB::table('referrals')->where('inviter_user_id', $inviterId)->count());
    }

    /**
     * Награда уже выплачена — уточнение не понижает статус.
     *
     * Уточнение `invited_user_id` живёт теперь в одном методе с `firstOrCreate()`,
     * и без этого теста его легко потерять при следующей правке: сама вставка
     * продолжит работать, а «не понижать `rewarded`» — нет.
     */
    public function test_a_rewarded_invitation_is_not_refined_back_to_registered(): void
    {
        $inviterId = $this->seedUser('inviter@example.com');
        $invitedId = $this->seedUser('friend@example.com');

        $referral = Referral::upsertByEmail($inviterId, 'friend@example.com');

        DB::table('referrals')->where('id', $referral->getKey())->update([
            'status' => Referral::STATUS_REWARDED,
        ]);

        $after = Referral::upsertByEmail($inviterId, 'friend@example.com', $invitedId);

        $this->assertSame(Referral::STATUS_REWARDED, (string) $after->status);
        $this->assertNull($after->invited_user_id, 'награда выплачена — уточнять нечего');
    }

    // ── фикстуры ───────────────────────────────────────────────────────────

    private function seedUser(string $email = 'buyer@example.com'): int
    {
        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }
}
