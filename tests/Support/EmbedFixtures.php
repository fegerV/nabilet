<?php

declare(strict_types=1);

namespace Nabilet\Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Фикстуры для `/api/v1/embed/*`.
 *
 * Embed-поверхность авторизуется не пользователем, а парой «строка `api_keys` со
 * скоупом `embed` + домен в `embed_domains`». Обе таблицы тесты обязаны заполнять
 * сами: фабрик у них нет, а `api_keys` пишется только через этот путь.
 *
 * `seedEmbedToken()` возвращает ТОЛЬКО открытый токен, потому что в базе лежит
 * `sha256` от него (`key_hash CHAR(64)`, UNIQUE). Это не деталь теста, а свойство
 * схемы: плейнтекста в базе нет ни в одной колонке, и тест, который «читает токен
 * из базы», проверить ничего не может.
 */
trait EmbedFixtures
{
    /** Домен, который тесты по умолчанию вносят в whitelist. */
    protected const EMBED_ORIGIN = 'https://partner.example';

    /**
     * Завести embed-ключ и вернуть открытый токен.
     *
     * `$organizationId` намеренно nullable: колонка `api_keys.organization_id`
     * NULLABLE, и «ключ без арендатора» — один из случаев, который гейт обязан
     * отказать. Передать его можно только явным `null`, поэтому сигнатура не
     * прячет это за значением по умолчанию.
     *
     * `$scopes` тоже nullable, и `null` здесь значит «в колонке NULL», а не
     * «пустой список»: `scopes_json` NULLABLE, и эти два состояния —
     * противоположные прочтения одной и той же пустоты (см. REVIEW §3.19).
     * `[]` пишет JSON `[]`, `null` оставляет колонку пустой.
     *
     * @param  list<string>|null  $scopes
     */
    protected function seedEmbedKey(
        ?int $organizationId,
        ?array $scopes = ['embed'],
        ?string $expiresAt = null,
        ?string $revokedAt = null,
    ): string {
        $token = 'embed_' . Str::random(40);

        DB::table('api_keys')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'Embed key',
            'key_prefix' => substr($token, 0, 8),
            'key_hash' => hash('sha256', $token),
            'scopes_json' => $scopes === null ? null : json_encode($scopes),
            'expires_at' => $expiresAt ?? now()->addYear()->toDateTimeString(),
            'revoked_at' => $revokedAt,
            'created_at' => now()->toDateTimeString(),
        ]);

        return $token;
    }

    /** Внести домен в whitelist организации. */
    protected function seedEmbedDomain(int $organizationId, string $domain, bool $active = true): void
    {
        $now = now()->toDateTimeString();

        DB::table('embed_domains')->insert([
            'organization_id' => $organizationId,
            'domain' => $domain,
            'active' => $active,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Готовый «всё настроено» набор: домен в whitelist + рабочий embed-токен.
     *
     * @return string открытый embed-токен
     */
    protected function seedEmbedAccess(int $organizationId, string $domain = 'partner.example'): string
    {
        $this->seedEmbedDomain($organizationId, $domain);

        return $this->seedEmbedKey($organizationId);
    }

    /**
     * Заголовки запроса виджета: Origin разрешённого сайта.
     *
     * @return array<string, string>
     */
    protected function embedHeaders(string $origin = self::EMBED_ORIGIN): array
    {
        return ['Origin' => $origin];
    }

    /** Путь с embed-токеном в query, как объявляет контракт. */
    protected function embedUrl(string $path, string $token): string
    {
        return $path . (str_contains($path, '?') ? '&' : '?') . 'embed_token=' . urlencode($token);
    }
}
