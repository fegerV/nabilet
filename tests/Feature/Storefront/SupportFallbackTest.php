<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Storefront\Services\StorefrontService;
use Tests\TestCase;

/**
 * Запасные контакты для виджета схемы зала.
 *
 * Виджет встраивается в чужой сайт. Если сервер продаж недоступен, покупатель
 * должен увидеть не пустую рамку, а телефон/мессенджеры организации. Конфиг
 * уезжает в браузер, поэтому всё проходит через белый список нормализации.
 */
final class SupportFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_phone_and_email_are_used_as_contacts(): void
    {
        DB::table('organizations')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'surgut-koncert',
            'email' => 'cash@surgut-concert.ru',
            'phone' => '+7 3462 25-00-00',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $support = $this->getJson('/api/v1/storefront')->assertOk()->json('data.support');

        self::assertSame('+7 3462 25-00-00', $support['phone']);
        self::assertSame('cash@surgut-concert.ru', $support['email']);
        self::assertNotSame('', $support['message']);
    }

    public function test_normalization_drops_unsafe_contacts(): void
    {
        $service = $this->app->make(StorefrontService::class);

        $support = $service->normalize([
            'support' => [
                'phone' => '+7 999 123-45-67<script>alert(1)</script>',
                'email' => 'not-an-email',
                'telegram' => 'javascript:alert(1)',
                'whatsapp' => 'https://wa.me/79991234567',
                'message' => str_repeat('x', 500),
            ],
        ])['support'];

        self::assertSame('', $support['phone'], 'телефон с разметкой не должен попасть в tel:');
        self::assertSame('', $support['email']);
        self::assertSame('', $support['telegram'], 'javascript: не должен оказаться в href');
        self::assertSame('https://wa.me/79991234567', $support['whatsapp']);
        self::assertSame(240, mb_strlen($support['message']));
    }

    public function test_saved_contacts_survive_a_round_trip(): void
    {
        $service = $this->app->make(StorefrontService::class);
        $config = $service->save(null, [
            'support' => [
                'phone' => '+7 999 000-11-22',
                'email' => 'box-office@example.ru',
                'telegram' => 'https://t.me/boxoffice',
                'whatsapp' => '',
                'message' => 'Звоните — держим места 30 минут.',
            ],
        ]);

        self::assertSame('+7 999 000-11-22', $config['support']['phone']);
        self::assertSame('https://t.me/boxoffice', $config['support']['telegram']);
        self::assertSame(
            'Звоните — держим места 30 минут.',
            $service->resolve(null)['support']['message'],
        );
    }
}
