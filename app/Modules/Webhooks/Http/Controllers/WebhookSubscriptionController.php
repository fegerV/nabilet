<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Http\Controllers;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Webhooks\Domain\DeliveryAttempt;
use Nabilet\Modules\Webhooks\Services\WebhookDispatchService;
use Nabilet\Modules\Webhooks\Services\WebhookEndpointGuard;

/**
 * Управление исходящими вебхуками организации.
 *
 * СЕКРЕТ ПОКАЗЫВАЕТСЯ ОДИН РАЗ — на создании. В БД лежит только
 * `secret_encrypted`; прочитать его обратно администратор не может, и это
 * правильно: секрет подписи уходит партнёру, а не хранится в админке, откуда
 * его можно вытащить скриншотом.
 *
 * Организация берётся из токена, никогда из тела запроса — иначе один
 * клиент подписал бы вебхуки на события другой организации.
 */
class WebhookSubscriptionController extends Controller
{
    public function __construct(
        private readonly WebhookEndpointGuard $endpointGuard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $webhooks = Webhook::query()
            ->where('organization_id', $this->organizationId($request))
            ->orderByDesc('id')
            ->get();

        return response()->json([
            // API client envelopes reads the payload from `data`; keep the
            // subscription list and event dictionary inside that object.
            'data' => [
                'subscriptions' => $webhooks
                    ->map(fn (Webhook $webhook): array => $this->present($webhook))
                    ->values(),
                'events' => WebhookDispatchService::availableEvents(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url:https', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookDispatchService::availableEvents())],
            'active' => ['sometimes', 'boolean'],
            'retry_limit' => ['sometimes', 'integer', 'min:0', 'max:50'],
        ]);

        // Разрешены только публичные HTTPS-адреса: этот сервер выполняет по ним
        // запросы, поэтому иначе можно атаковать внутреннюю сеть через SSRF.
        $this->endpointGuard->resolve($data['url']);

        $secret = Str::random(48);

        $webhook = Webhook::query()->create([
            'organization_id' => $this->organizationId($request),
            'url' => $data['url'],
            'secret_encrypted' => Crypt::encryptString($secret),
            // Модель не объявляет cast для events_json, поэтому массив
            // сериализуется здесь: без этого Eloquent передал бы его в
            // JSON-колонку как есть и получил ошибку биндинга.
            'events_json' => json_encode(array_values($data['events']), JSON_UNESCAPED_UNICODE),
            'active' => (bool) ($data['active'] ?? true),
            'retry_limit' => (int) ($data['retry_limit'] ?? DeliveryAttempt::DEFAULT_RETRY_LIMIT),
        ]);

        return response()->json([
            'data' => ['secret' => $secret] + $this->present($webhook),
        ], 201);
    }

    public function update(Request $request, int $webhook): JsonResponse
    {
        $model = $this->find($request, $webhook);

        $data = $request->validate([
            'url' => ['sometimes', 'url:https', 'max:2048'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookDispatchService::availableEvents())],
            'active' => ['sometimes', 'boolean'],
            'retry_limit' => ['sometimes', 'integer', 'min:0', 'max:50'],
        ]);

        if (isset($data['url'])) {
            $this->endpointGuard->resolve($data['url']);
        }

        $payload = [];

        foreach (['url', 'active', 'retry_limit'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $field === 'retry_limit' ? (int) $data[$field] : $data[$field];
            }
        }

        if (isset($data['events'])) {
            $payload['events_json'] = json_encode(array_values($data['events']), JSON_UNESCAPED_UNICODE);
        }

        $model->update($payload);

        return response()->json([
            'data' => $this->present($model->fresh() ?? $model),
        ]);
    }

    public function destroy(Request $request, int $webhook): JsonResponse
    {
        $model = $this->find($request, $webhook);
        $model->delete(); // доставки удаляются каскадом по FK

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Последние доставки вебхука — то, что поддержка смотрит, когда партнёр
     * говорит «мы ничего не получили».
     */
    public function deliveries(Request $request, int $webhook): JsonResponse
    {
        $model = $this->find($request, $webhook);

        $deliveries = WebhookDelivery::query()
            ->where('webhook_id', $model->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $deliveries->map(static fn (WebhookDelivery $d): array => [
                'id' => $d->id,
                'delivery_id' => $d->delivery_id,
                'event_name' => $d->event_name,
                'status_code' => $d->status_code,
                'attempt' => $d->attempt,
                'error_message' => $d->error_message,
                'delivered_at' => $d->delivered_at?->toIso8601String(),
                'next_retry_at' => $d->next_retry_at?->toIso8601String(),
                'created_at' => $d->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    private function find(Request $request, int $id): Webhook
    {
        return Webhook::query()
            ->where('organization_id', $this->organizationId($request))
            ->findOrFail($id);
    }

    private function organizationId(Request $request): int
    {
        $id = $request->user()?->organizations()->first()?->id;

        if ($id === null) {
            throw new DomainRuleViolation(
                'Не удалось определить организацию пользователя.',
                'ORGANIZATION_REQUIRED',
                [],
                403,
            );
        }

        return (int) $id;
    }

    /** @return array<string, mixed> */
    private function present(Webhook $webhook): array
    {
        $events = $webhook->events_json;

        if (is_string($events)) {
            $events = json_decode($events, true);
        }

        return [
            'id' => $webhook->id,
            'public_id' => $webhook->public_id,
            'url' => $webhook->url,
            'events' => is_array($events) ? array_values($events) : [],
            'active' => (bool) $webhook->active,
            'retry_limit' => (int) $webhook->retry_limit,
        ];
    }
}
