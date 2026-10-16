<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Подписка на рассылку афиши (`POST /subscribe`, отписка по токену).
 *
 * Замыкает honesty-дефект виджета «Подписка» (аудит лояльности L4): форма
 * валидировала e-mail и рисовала «Готово», но адрес никуда не уходил.
 */
final class NewsletterSubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscribe_creates_subscriber_with_consent(): void
    {
        $this->postJson('/api/v1/subscribe', ['email' => 'Fan@Example.test'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'subscribed');

        $row = DB::table('newsletter_subscribers')->where('email', 'fan@example.test')->first();

        $this->assertNotNull($row, 'адрес нормализуется в нижний регистр и сохраняется');
        $this->assertSame('subscribed', $row->status);
        $this->assertSame(36, strlen((string) $row->anonymous_id));

        // ФАКТ согласия пишется в consents с тем же anonymous_id (152-ФЗ §9).
        $this->assertSame(
            1,
            (int) DB::table('consents')
                ->where('anonymous_id', $row->anonymous_id)
                ->where('consent_type', 'newsletter')
                ->where('status', 'granted')
                ->count()
        );
    }

    public function test_resubscribing_the_same_address_does_not_duplicate(): void
    {
        $this->postJson('/api/v1/subscribe', ['email' => 'fan@example.test'])->assertStatus(201);
        $this->postJson('/api/v1/subscribe', ['email' => 'fan@example.test'])->assertStatus(201);

        $this->assertSame(1, (int) DB::table('newsletter_subscribers')->count());
    }

    public function test_resubscribe_after_unsubscribe_issues_a_fresh_token(): void
    {
        $this->postJson('/api/v1/subscribe', ['email' => 'fan@example.test'])->assertStatus(201);

        $first = DB::table('newsletter_subscribers')->where('email', 'fan@example.test')->first();

        $this->getJson('/api/v1/unsubscribe/' . $first->unsubscribe_token)
            ->assertOk()
            ->assertJsonPath('data.unsubscribed', true);

        $this->assertSame(
            'unsubscribed',
            (string) DB::table('newsletter_subscribers')->where('email', 'fan@example.test')->value('status')
        );

        // Повторная подписка: адрес оживает, старый токен перестаёт работать.
        $this->postJson('/api/v1/subscribe', ['email' => 'fan@example.test'])->assertStatus(201);

        $second = DB::table('newsletter_subscribers')->where('email', 'fan@example.test')->first();

        $this->assertNotSame($first->unsubscribe_token, $second->unsubscribe_token);

        $this->getJson('/api/v1/unsubscribe/' . $first->unsubscribe_token)
            ->assertOk()
            ->assertJsonPath('data.unsubscribed', false);
    }

    public function test_unknown_token_answers_without_leaking(): void
    {
        $this->getJson('/api/v1/unsubscribe/no-such-token')
            ->assertOk()
            ->assertJsonPath('data.unsubscribed', false);
    }

    public function test_invalid_email_is_a_validation_error(): void
    {
        $this->postJson('/api/v1/subscribe', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, (int) DB::table('newsletter_subscribers')->count());
    }
}
