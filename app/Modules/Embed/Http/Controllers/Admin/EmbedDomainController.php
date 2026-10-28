<?php

declare(strict_types=1);

namespace Nabilet\Modules\Embed\Http\Controllers\Admin;

use Nabilet\Core\Support\Paging;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Database\IdentifierLookup;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Analytics\Models\EmbedDomain;
use Nabilet\Modules\Embed\Domain\EmbedOrigin;

/**
 * Whitelist доменов, на которых организация разрешает встраивание
 * (`openapi.yaml`: `GET/POST /api/v1/admin/embed/domains`,
 * `PATCH/DELETE .../domains/{embedDomain}`).
 *
 * ЗАЧЕМ ЭТА ЧАСТЬ ПОЯВИЛАСЬ
 *   `EmbedAccessService::assertOriginAllowed()` с самого начала читал
 *   `embed_domains` и отказывал всем, если список пуст — то есть организация без
 *   записей в этой таблице не могла быть встроена нигде. Записей же не создавал
 *   ни один код приложения: whitelist существовал как колонки, а не как
 *   возможность. Здесь появляется то, чем его заполняют.
 *
 * ПОЧЕМУ НОРМАЛИЗАЦИЯ — НЕ В КОНТРОЛЛЕРЕ
 *   `EmbedOrigin::normalizeDomain()` — владелец формата. `isAllowed()`
 *   сравнивает голый хост в нижнем регистре, поэтому записанный «как ввели»
 *   `https://partner.example` не совпал бы НИКОГДА, и ошибки бы не возникло:
 *   виджет просто получил бы 403 на разрешённом сайте. Один владелец на запись
 *   и чтение — единственный способ, которым эти две половины не разойдутся.
 *
 * ПОЧЕМУ УДАЛЕНИЕ ЗДЕСЬ ДОПУСТИМО, ХОТЯ У КЛЮЧЕЙ — НЕТ
 *   На `embed_domains` никто не ссылается: это независимый список, и удаление
 *   строки не отрывает историю от факта. У `api_keys` наоборот — `revoked_at`
 *   единственный след существования ключа, поэтому там только отзыв. Удаление
 *   домена ничего не стирает: отсутствие записи и раньше означало «нельзя».
 */
final class EmbedDomainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = Paging::limit((int) $request->query('per_page', '50'), 50, 100);

        $domains = EmbedDomain::query()
            ->when($request->query('organization_id'), fn ($q, $id) => $q->where('organization_id', $id))
            ->orderBy('domain')
            ->paginate($perPage);

        return response()->json([
            'data' => $domains->items(),
            'meta' => [
                'current_page' => $domains->currentPage(),
                'per_page' => $domains->perPage(),
                'total' => $domains->total(),
                'last_page' => $domains->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'bail', 'integer', 'exists:organizations,id'],
            'domain' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $domain = EmbedOrigin::normalizeDomain((string) $validated['domain']);

        if ($domain === null) {
            throw new ValidationError(
                ['domain' => [
                    'This is not a site a widget can be embedded on. Write a host such as '
                    . '"partner.example", or ".partner.example" to include its subdomains.',
                ]],
                'The embed domain is invalid.',
                [],
                'EMBED_DOMAIN_INVALID',
            );
        }

        // Гонка на уникальном индексе `uq_embed_domains_org_domain` разрешается
        // `createOrFirst()`, а не «проверить и вставить»: между проверкой и
        // вставкой второй запрос успевает вставить ту же строку, и тогда
        // вылетает 1062 вместо ответа. `wasRecentlyCreated` отвечает на вопрос
        // «это вставил я», поэтому дубль виден как 409, а не как успех.
        $row = EmbedDomain::query()->createOrFirst(
            [
                'organization_id' => (int) $validated['organization_id'],
                'domain' => $domain,
            ],
            ['active' => (bool) ($validated['active'] ?? true)],
        );

        if (! $row->wasRecentlyCreated) {
            throw new ConflictError(
                sprintf('The domain "%s" is already on this organization\'s embed list.', $domain),
                'EMBED_DOMAIN_ALREADY_LISTED',
                ['domain' => $domain],
            );
        }

        return response()->json(['data' => $row], 201);
    }

    /**
     * Включить или выключить домен, не удаляя его.
     *
     * `EmbedAccessService` читает только `active = true`, поэтому выключенная
     * запись перестаёт пускать сайт, но остаётся в списке — это разница между
     * «мы этот сайт выключили» и «мы его никогда не вносили».
     */
    public function update(Request $request, string $embedDomain): JsonResponse
    {
        $row = IdentifierLookup::firstOrFail(EmbedDomain::class, $embedDomain, 'Embed domain');

        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $row->update(['active' => (bool) $validated['active']]);

        return response()->json(['data' => $row->fresh()]);
    }

    public function destroy(string $embedDomain): JsonResponse
    {
        $row = IdentifierLookup::firstOrFail(EmbedDomain::class, $embedDomain, 'Embed domain');

        $row->delete();

        return response()->json(['message' => 'Embed domain deleted successfully']);
    }
}
