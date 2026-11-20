<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Tests\Support\EmbedFixtures;
use Tests\TestCase;

/**
 * Заголовки рамки: без них виджет на чужом сайте не откроется вовсе.
 *
 * Сниппет из админки грузит обычную витрину с `?embed_token=…`. Если middleware
 * продолжает ставить `X-Frame-Options: SAMEORIGIN` и `frame-ancestors 'self'`,
 * браузер партнёрского сайта блокирует iframe — и никакое fallback-окно об
 * ошибке загрузки схемы пользователь не увидит.
 */
final class EmbedFrameHeadersTest extends TestCase
{
    use EmbedFixtures;
    use RefreshDatabase;

    public function test_a_configured_partner_site_may_frame_the_widget(): void
    {
        $token = $this->seedEmbedAccess($this->organizationId(), 'partner.example');

        $response = $this->get('/api/v1/health?embed_token=' . urlencode($token));

        $response->assertOk()->assertHeaderMissing('X-Frame-Options');
        $this->assertStringContainsString(
            'frame-ancestors https://partner.example http://partner.example',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_an_unknown_token_fails_closed(): void
    {
        $response = $this->get('/api/v1/health?embed_token=never-issued');

        $response->assertOk()->assertHeaderMissing('X-Frame-Options');
        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_ordinary_pages_keep_sameorigin_protection(): void
    {
        $response = $this->get('/api/v1/health');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    private function organizationId(): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'surgut-koncert',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
