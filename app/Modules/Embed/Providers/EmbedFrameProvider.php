<?php

declare(strict_types=1);

namespace Nabilet\Modules\Embed\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Nabilet\Core\Support\Sha256Hex;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Analytics\Models\EmbedDomain;
use Nabilet\Modules\Embed\Domain\EmbedScope;
use Nabilet\Modules\Embed\Services\EmbedAccessService;
use Nabilet\Modules\Security\Domain\ApiKeyPolicy;
use Nabilet\Modules\System\Models\ApiKey;

/**
 * Кто может обрамлять витрину в iframe (ТЗ §50).
 *
 * `ApplySecurityHeaders` ставит `frame-ancestors 'self'` для обычных страниц и
 * снимает этот барьер только для embed-запросов. Раньше хук
 * `embed.allowed_ancestors` НИКТО не регистрировал — то есть ни одна партнёрская
 * рамка не открывалась, а fallback-окно виджета не появлялось вместе с ней.
 * Здесь хук получает список доменов организации из embed-ключа.
 *
 * Fail closed: нет токена, ключ не активен, домены не настроены — пустой список,
 * middleware превращает его в `frame-ancestors 'none'`. Белый список доменов —
 * тот же, что проверяет `EmbedAccessService` для /api/v1/embed/*.
 */
final class EmbedFrameProvider extends ServiceProvider
{
    public function boot(): void
    {
        add_filter('embed.allowed_ancestors', function (mixed $value, Request $request): array {
            return $this->allowedOrigins($request);
        }, 10, 2);
    }

    /** @return list<string> */
    private function allowedOrigins(Request $request): array
    {
        $token = trim((string) $request->query(EmbedAccessService::TOKEN_QUERY, ''));
        if ($token === '') {
            return [];
        }

        /** @var ApiKey|null $key */
        $key = app(OrganizationContext::class)->withoutScope(
            fn (): ?ApiKey => ApiKey::query()->where('key_hash', Sha256Hex::of($token))->first()
        );
        if ($key === null || $key->organization_id === null) {
            return [];
        }

        $decision = app(ApiKeyPolicy::class)->scopeDecision(
            $key->toDomain(),
            EmbedScope::EMBED,
            CarbonImmutable::now(),
        );
        if (! $decision->isAllowed()) {
            return [];
        }

        /** @var list<string> $domains */
        $domains = app(OrganizationContext::class)->withoutScope(
            fn (): array => EmbedDomain::query()
                ->where('organization_id', (int) $key->organization_id)
                ->where('active', true)
                ->pluck('domain')
                ->all()
        );

        $origins = [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            if (str_starts_with($domain, '.')) {
                $host = substr($domain, 1);
                if ($host === '') {
                    continue;
                }
                $origins[] = 'https://' . $host;
                $origins[] = 'https://*.' . $host;
                continue;
            }
            $origins[] = 'https://' . $domain;
            $origins[] = 'http://' . $domain;
        }

        return $origins;
    }
}
