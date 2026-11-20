<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Services;

use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Agent\Models\AgentAction;
use Nabilet\Modules\Agent\Support\AgentCapabilities;
use Nabilet\Modules\Agent\Support\AgentScope;
use Nabilet\Modules\Events\Services\EventService;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Services\RefundService;
use Nabilet\Modules\Venues\Halls\Services\HallService;
use Nabilet\Modules\Venues\Models\Venue;

/**
 * Двухфазные действия агента: prepare → человек одобряет → confirm.
 *
 * Ни один write-инструмент не меняет бизнес-данные в prepare-фазе. ВБД
 * сохраняется снимок payload и резюме; человек одобряет именно его; confirm
 * исполняет сохранённый снимок, а НЕ аргументы повторного вызова. Это главное
 * отличие от «агент просто вызвал сервис»: даже если модель в диалоге
 * передумала, она не может подменить уже одобренное действие.
 */
final class AgentActionService
{
    private const TTL_MINUTES = 30;

    // Типы действий агента — один владелец, чтобы их нельзя было сравнить
    // голой строкой в логике подтверждения (env-тест TransactionTypeCopy
    // сторожит тот же подход для типов транзакций).
    public const ACTION_CREATE_EVENT = 'create_event';
    public const ACTION_PUBLISH_SCHEMA = 'publish_schema';
    public const ACTION_REFUND = 'refund';

    public function __construct(
        private readonly EventService $events,
        private readonly HallService $halls,
        private readonly RefundService $refunds,
        private readonly OrganizationContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationError
     */
    public function prepare(
        object $user,
        string $action,
        string $capability,
        array $payload,
        string $summary,
        ?int $organizationId = null,
    ): AgentAction {
        if (! AgentCapabilities::has($user, $capability)) {
            throw new ValidationError([
                'capability' => ['Токен не имеет полномочия ' . $capability . '.'],
            ]);
        }

        $scope = AgentScope::resolve($user);
        if ($scope === null) {
            throw new ValidationError(['organization_id' => ['Токен не привязан ни к одной организации.']]);
        }

        $orgIds = $scope->organizationIds;
        if ($organizationId !== null && ! in_array($organizationId, $orgIds, true)) {
            throw new ValidationError(['organization_id' => ['Организация не принадлежит владельцу токена.']]);
        }
        if ($organizationId === null && count($orgIds) !== 1) {
            throw new ValidationError([
                'organization_id' => ['У токена несколько организаций — укажите organization_id явно.'],
            ]);
        }
        $organizationId ??= $orgIds[0];

        $payload = $this->validatePayload($action, $payload, $organizationId);
        $summary = trim($summary);
        if ($summary === '' || mb_strlen($summary) > 1000) {
            throw new ValidationError(['summary' => ['Краткое описание действия обязательно (до 1000 знаков).']]);
        }

        $tokenId = method_exists($user, 'currentAccessToken')
            && $user->currentAccessToken() !== null
            ? (int) $user->currentAccessToken()->getKey()
            : null;

        /** @var AgentAction $pending */
        $pending = $this->tenant->withoutScope(fn (): AgentAction => AgentAction::query()->create([
            'public_id' => \Nabilet\Modules\Core\Support\PublicId::generate(),
            'organization_id' => $organizationId,
            'user_id' => (int) $user->getAuthIdentifier(),
            'personal_access_token_id' => $tokenId,
            'action' => $action,
            'capability' => $capability,
            'payload_json' => $payload,
            'summary' => $summary,
            'status' => AgentAction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]));

        $this->audit($organizationId, 'agent_action_prepared', $pending->id, [
            'action' => $action,
            'public_id' => $pending->public_id,
            'summary' => $summary,
        ]);

        return $pending;
    }

    /** Человек одобряет подготовленное действие в админке. */
    public function approve(AgentAction $action, object $approver, ?string $confirmPhrase = null): void
    {
        if ($action->status !== AgentAction::STATUS_PENDING) {
            throw new ValidationError(['status' => ['Действие уже обработано.']]);
        }
        if ($action->expires_at !== null && $action->expires_at->isPast()) {
            $action->update(['status' => AgentAction::STATUS_EXPIRED]);
            throw new ValidationError(['status' => ['Срок подтверждения истёк — подготовьте действие заново.']]);
        }
        if ($action->action === self::ACTION_REFUND && strtoupper(trim((string) $confirmPhrase)) !== 'REFUND') {
            throw new ValidationError(['confirm_phrase' => ['Для возврата введите REFUND.']]);
        }

        $scope = AgentScope::resolve($approver);
        if ($scope === null || ! in_array((int) $action->organization_id, $scope->organizationIds, true)) {
            throw new ValidationError(['organization_id' => ['Нет доступа к организации этого действия.']]);
        }

        $action->update([
            'status' => AgentAction::STATUS_APPROVED,
            'approved_by_user_id' => (int) $approver->getAuthIdentifier(),
            'approved_at' => now(),
        ]);

        $this->audit((int) $action->organization_id, 'agent_action_approved', $action->id, [
            'action' => $action->action,
            'public_id' => $action->public_id,
            'approved_by' => (int) $approver->getAuthIdentifier(),
        ]);
    }

    public function reject(AgentAction $action, object $approver): void
    {
        if ($action->status !== AgentAction::STATUS_PENDING) {
            throw new ValidationError(['status' => ['Действие уже обработано.']]);
        }
        $action->update(['status' => AgentAction::STATUS_REJECTED]);
        $this->audit((int) $action->organization_id, 'agent_action_rejected', $action->id, [
            'public_id' => $action->public_id,
            'rejected_by' => (int) $approver->getAuthIdentifier(),
        ]);
    }

    /**
     * Исполнить одобренное действие. Повторный вызов возвращает сохранённый
     * результат и НЕ исполняет бизнес-логику второй раз.
     *
     * @return array<string, mixed>
     */
    public function execute(AgentAction $action, object $user): array
    {
        $scope = AgentScope::resolve($user);
        if ($scope === null || ! in_array((int) $action->organization_id, $scope->organizationIds, true)) {
            throw new ValidationError(['public_id' => ['Действие не найдено.']]);
        }

        if ($action->status === AgentAction::STATUS_EXECUTED && is_array($action->result_json)) {
            return $action->result_json;
        }
        if ($action->status !== AgentAction::STATUS_APPROVED) {
            throw new ValidationError(['status' => ['Действие не одобрено человеком.']]);
        }
        if ($action->expires_at !== null && $action->expires_at->isPast()) {
            $action->update(['status' => AgentAction::STATUS_EXPIRED]);
            throw new ValidationError(['status' => ['Срок подтверждения истёк.']]);
        }
        if (! AgentCapabilities::has($user, (string) $action->capability)) {
            throw new ValidationError(['capability' => ['У токена нет полномочия на это действие.']]);
        }

        try {
            $result = DB::transaction(fn (): array => match ($action->action) {
                'create_event' => $this->executeCreateEvent($action),
                'publish_schema' => $this->executePublishSchema($action, $user),
                'refund' => $this->executeRefund($action),
                default => throw new ValidationError(['action' => ['Неизвестный тип действия.']]),
            });

            $action->update([
                'status' => AgentAction::STATUS_EXECUTED,
                'executed_at' => now(),
                'result_json' => $result,
                'error' => null,
            ]);
            $this->audit((int) $action->organization_id, 'agent_action_executed', $action->id, [
                'public_id' => $action->public_id,
                'action' => $action->action,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $action->update([
                'status' => AgentAction::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(string $action, array $payload, int $organizationId): array
    {
        return match ($action) {
            'create_event' => $this->validateCreateEvent($payload, $organizationId),
            'publish_schema' => $this->validatePublishSchema($payload, $organizationId),
            'refund' => $this->validateRefund($payload, $organizationId),
            default => throw new ValidationError(['action' => ['Неизвестный тип действия.']]),
        };
    }

    /** @param array<string, mixed> $p */
    private function validateCreateEvent(array $p, int $org): array
    {
        $v = validator($p, [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:60000'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'schema_version_id' => ['required', 'integer', 'exists:hall_schema_versions,id'],
            'poster' => ['nullable', 'url', 'max:2000'],
            'age_limit' => ['nullable', 'string', 'max:16'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ])->validate();

        $this->assertVenueInOrg((int) $v['venue_id'], $org);
        if (isset($v['schema_version_id'])) {
            $schemaOrg = DB::table('hall_schema_versions')->where('id', $v['schema_version_id'])->value('organization_id');
            if ((int) $schemaOrg !== $org) {
                throw new ValidationError(['schema_version_id' => ['Схема другой организации.']]);
            }
        }

        $slug = $v['slug'] ?? \Illuminate\Support\Str::slug((string) $v['title']);
        if ($slug === '') {
            $slug = 'event-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8));
        }
        if (DB::table('events')->where('slug', $slug)->exists()) {
            $slug .= '-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6));
        }
        $v['slug'] = $slug;
        $v['organization_id'] = $org;

        return $v;
    }

    /** @param array<string, mixed> $p */
    private function validatePublishSchema(array $p, int $org): array
    {
        $v = validator($p, [
            'venue_public_id' => ['required', 'string', 'max:26'],
            'schema_version_id' => ['required', 'integer', 'min:1'],
            'base_revision' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $venue = $this->tenant->withoutScope(fn () => Venue::query()
            ->where('public_id', $v['venue_public_id'])
            ->where('organization_id', $org)
            ->first());
        if ($venue === null) {
            throw new ValidationError(['venue_public_id' => ['Площадка не найдена.']]);
        }
        $schemaOrg = DB::table('hall_schema_versions')->where('id', $v['schema_version_id'])->value('organization_id');
        if ((int) $schemaOrg !== $org) {
            throw new ValidationError(['schema_version_id' => ['Схема другой организации.']]);
        }
        $v['organization_id'] = $org;

        return $v;
    }

    /** @param array<string, mixed> $p */
    private function validateRefund(array $p, int $org): array
    {
        $v = validator($p, [
            'order_public_id' => ['required', 'string', 'max:26'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $order = $this->tenant->withoutScope(fn () => Order::query()
            ->where('public_id', $v['order_public_id'])
            ->where('organization_id', $org)
            ->first());
        if ($order === null) {
            throw new ValidationError(['order_public_id' => ['Заказ не найден.']]);
        }
        if (isset($v['amount'])) {
            $paid = (int) DB::table('payment_transactions')
                ->join('payments', 'payments.id', '=', 'payment_transactions.payment_id')
                ->where('payments.order_id', $order->id)
                ->where('payments.status', 'succeeded')
                ->sum('payment_transactions.amount');
            if ((int) $v['amount'] > $paid) {
                throw new ValidationError(['amount' => ['Сумма возврата больше оплаченной.']]);
            }
        }
        $v['organization_id'] = $org;
        $v['order_id'] = (int) $order->id;

        return $v;
    }

    private function assertVenueInOrg(int $venueId, int $org): void
    {
        $venueOrg = DB::table('venues')->where('id', $venueId)->value('organization_id');
        if ((int) $venueOrg !== $org) {
            throw new ValidationError(['venue_id' => ['Площадка другой организации.']]);
        }
    }

    /** @return array<string, mixed> */
    private function executeCreateEvent(AgentAction $action): array
    {
        $p = $action->payload_json;
        /** @var \Nabilet\Modules\Events\Models\Event $event */
        $event = $this->events->create([
            'organization_id' => $p['organization_id'],
            'title' => $p['title'],
            'slug' => $p['slug'],
            'description' => $p['description'] ?? null,
            'short_description' => $p['short_description'] ?? null,
            'starts_at' => $p['starts_at'],
            'ends_at' => $p['ends_at'] ?? null,
            'timezone' => $p['timezone'] ?? 'Europe/Moscow',
            'venue_id' => $p['venue_id'],
            'schema_version_id' => $p['schema_version_id'] ?? null,
            'poster' => $p['poster'] ?? null,
            'age_limit' => $p['age_limit'] ?? null,
            'duration_minutes' => $p['duration_minutes'] ?? null,
            // Агент не может опубликовать в обход EventPublicationPolicy.
            'status' => \Nabilet\Modules\Events\Domain\EventStatus::DRAFT,
        ]);

        return [
            'event_public_id' => $event->public_id,
            'slug' => $event->slug,
            'status' => $event->status,
            'message' => 'Черновик мероприятия создан; опубликуйте его штатным переходом после проверки.',
        ];
    }

    /** @return array<string, mixed> */
    private function executePublishSchema(AgentAction $action, object $user): array
    {
        $p = $action->payload_json;
        $version = $this->halls->publishSchemaVersion(
            (int) $p['schema_version_id'],
            (int) $user->getAuthIdentifier(),
            (string) $p['venue_public_id'],
            isset($p['base_revision']) ? (int) $p['base_revision'] : null,
        );

        return [
            'schema_version_id' => (int) $version->id,
            'version' => (int) $version->version,
            'status' => (string) $version->status,
        ];
    }

    /** @return array<string, mixed> */
    private function executeRefund(AgentAction $action): array
    {
        $p = $action->payload_json;
        $payments = $this->tenant->withoutScope(fn () => Payment::query()
            ->where('order_id', $p['order_id'])
            ->where('status', 'succeeded')
            ->get());

        $done = [];
        foreach ($payments as $payment) {
            $updated = $this->refunds->refund($payment, isset($p['amount']) ? (int) $p['amount'] : null, $p['reason'] ?? null);
            $done[] = [
                'payment_public_id' => $updated->public_id,
                'status' => (string) $updated->status,
            ];
        }

        return ['refunds' => $done, 'count' => count($done)];
    }

    /** @param array<string, mixed> $new */
    private function audit(int $organizationId, string $action, int $entityId, array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => null,
            'organization_id' => $organizationId,
            'action' => $action,
            'entity_type' => 'agent_action',
            'entity_id' => $entityId,
            'old_values_json' => null,
            'new_values_json' => json_encode($new, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => now(),
        ]);
    }
}
