<?php

declare(strict_types=1);

namespace Nabilet\Modules\Embed\Http\Controllers\Admin;

use Nabilet\Core\Support\Paging;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Database\IdentifierLookup;
use Nabilet\Modules\Embed\Services\EmbedTokenIssuer;
use Nabilet\Modules\System\Models\ApiKey;

/**
 * Выпуск и отзыв embed-ключей
 * (`openapi.yaml`: `GET/POST /api/v1/admin/embed/keys`, `PATCH .../keys/{key}`).
 *
 * ЗАЧЕМ ЭТА ЧАСТЬ ВООБЩЕ ПОЯВИЛАСЬ
 *   `EmbedAccessService` проверял пару «ключ со скоупом `embed` + Origin» с
 *   самого начала, но ключ выпускать было нечем: `api_keys` не писал ни один
 *   код приложения. То есть весь `/api/v1/embed/*` был закрыт наглухо — 403
 *   `EMBED_TOKEN_REQUIRED` на любой запрос, — и встраивание нельзя было
 *   включить никакой настройкой. Три эндпоинта здесь и есть та половина
 *   контура, без которой вторая половина недостижима.
 *
 * ТОКЕН ВОЗВРАЩАЕТСЯ РОВНО ОДИН РАЗ, В ОТВЕТЕ НА `POST`
 *   В базе только `Sha256Hex::of($token)`. Ни список, ни `PATCH` его не отдают:
 *   иначе «показать токен ещё раз» стало бы чтением секрета из обычного
 *   админского запроса. Потерянный токен — это отзыв и выпуск нового.
 *
 * ОТЗЫВ, А НЕ УДАЛЕНИЕ
 *   `revoked_at`, и обратно он не снимается: в таблице нет истории, поэтому
 *   `NULL` стёр бы сам факт отзыва (см. `ApiKeyPolicy::revokeDecision()`).
 *   Удалять строку нельзя и по другой причине: она — единственное, что
 *   отличает «ключом не пользовались» от «ключа не было».
 */
final class EmbedKeyController extends Controller
{
    public function __construct(
        private readonly EmbedTokenIssuer $issuer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = Paging::limit((int) $request->query('per_page', '50'), 50, 100);

        // `key_hash` скрыт на модели (`$hidden`), поэтому `toArray()` его не
        // отдаст даже здесь: секрет не должен уезжать в ответ списка.
        $keys = ApiKey::query()
            ->when($request->query('organization_id'), fn ($q, $id) => $q->where('organization_id', $id))
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $keys->items(),
            'meta' => [
                'current_page' => $keys->currentPage(),
                'per_page' => $keys->perPage(),
                'total' => $keys->total(),
                'last_page' => $keys->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'bail', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            // Срок обязателен, а не «по умолчанию год»: `ApiKeyPolicy` отказывает
            // бессрочному ключу, потому что креденшл без срока нельзя вывести из
            // обращения. Требование на границе — чтобы отказ приходил 422 на
            // `expires_at`, а не 500 из глубины политики.
            'expires_at' => ['required', 'date', 'after:now'],
        ]);

        $issued = $this->issuer->issue(
            organizationId: (int) $validated['organization_id'],
            name: (string) $validated['name'],
            expiresAt: CarbonImmutable::parse((string) $validated['expires_at']),
        );

        return response()->json([
            'data' => [
                'key' => $issued['key'],
                // Единственный момент, когда токен существует вне процесса
                // выдачи. Дальше — только хеш.
                'token' => $issued['token'],
            ],
        ], 201);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        // Разрешение идентификатора и отказ при «не нашли» — у одного владельца
        // (`IdentifierLookup::firstOrFail`), а не своей копией политики 404.
        $model = IdentifierLookup::firstOrFail(ApiKey::class, $key, 'Embed key');

        $request->validate([
            // ТОЛЬКО `revoked: true`. Отзыв необратим, и это не удобство, а
            // смысл отзыва: в таблице нет истории, поэтому снятый `revoked_at`
            // не оставил бы следа. `false` отвергается правилом `accepted` —
            // оживить ключ может только выпуск нового.
            'revoked' => ['sometimes', 'boolean', 'accepted'],
        ]);

        if ($request->has('revoked')) {
            $model = $this->issuer->revoke($model);
        }

        return response()->json(['data' => $model]);
    }
}
