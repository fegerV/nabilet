<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Nabilet\Modules\Cart\Services\CartItemService;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Notifications\Mail\TemplateMail;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Напоминание о брошенной корзине АВТОРИЗОВАННОГО покупателя
 * (`carts:remind-abandoned`).
 *
 * Гостевые корзины НЕ напоминаются принципиально: контакта до чекаута не
 * существует, а захват адреса раньше времени — анти-паттерн.
 */
final class AbandonedCartReminderTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private CartItemService $items;

    protected function setUp(): void
    {
        parent::setUp();

        $this->items = $this->app->make(CartItemService::class);
    }

    private function user(string $email): User
    {
        $id = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    /** Корзина пользователя с одной позицией и заданным остатком жизни. */
    private function cartFor(User $user, int $minutesLeft): int
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 3);

        $this->items->addItem(
            (string) $sessionId,
            (int) $inventoryItemId,
            1,
            'token-' . Str::random(12),
            $user,
        );

        DB::table('carts')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->update(['expires_at' => now()->addMinutes($minutesLeft)->toDateTimeString()]);

        return (int) DB::table('carts')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('id');
    }

    public function test_reminds_owner_when_expiry_is_close(): void
    {
        Mail::fake();

        $user = $this->user('club.example@example.test');
        $cartId = $this->cartFor($user, minutesLeft: 4);

        $this->artisan('carts:remind-abandoned');

        Mail::assertSent(TemplateMail::class, 1);

        Mail::assertSent(TemplateMail::class, function (TemplateMail $mail) use ($user): bool {
            $this->assertTrue($mail->hasTo($user->email));
            $this->assertStringContainsString('истекает', (string) $mail->textBody);

            return true;
        });

        $this->assertNotNull(
            DB::table('carts')->where('id', $cartId)->value('reminder_sent_at')
        );
    }

    public function test_reminds_only_once_per_cart(): void
    {
        Mail::fake();

        $user = $this->user('once@example.test');
        $this->cartFor($user, minutesLeft: 4);

        $this->artisan('carts:remind-abandoned');
        $this->artisan('carts:remind-abandoned');

        Mail::assertSent(TemplateMail::class, 1);
    }

    public function test_cart_with_plenty_of_time_is_not_reminded(): void
    {
        Mail::fake();

        $user = $this->user('fresh@example.test');
        $this->cartFor($user, minutesLeft: 13);

        $this->artisan('carts:remind-abandoned');

        Mail::assertNotSent(TemplateMail::class);
    }

    public function test_guest_cart_is_never_reminded(): void
    {
        Mail::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 3);

        // Гостевая корзина: тот же addItem, но без $buyer.
        $this->items->addItem((string) $sessionId, (int) $inventoryItemId, 1, 'token-guest-1');

        DB::table('carts')
            ->whereNull('user_id')
            ->where('status', 'active')
            ->update(['expires_at' => now()->addMinutes(4)->toDateTimeString()]);

        $this->artisan('carts:remind-abandoned');

        Mail::assertNotSent(TemplateMail::class);
    }
}
