<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Agent\Models\AgentAction;
use Nabilet\Modules\Agent\Support\AgentCapabilities;
use Nabilet\Modules\Agent\Support\AgentScope;
use Nabilet\Modules\Events\Models\Event;
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
    public const ACTION_CREATE_PAGE = 'create_page';
    public const ACTION_UPDATE_PAGE = 'update_page';
    public const ACTION_UPDATE_THEME = 'update_theme';
    public const ACTION_UPDATE_SEO = 'update_seo';
    public const ACTION_UPDATE_EVENT = 'update_event';

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
                self::ACTION_CREATE_PAGE => $this->executeCreatePage($action),
                self::ACTION_UPDATE_PAGE => $this->executeUpdatePage($action),
                self::ACTION_UPDATE_THEME => $this->executeUpdateTheme($action, $user),
                self::ACTION_UPDATE_SEO => $this->executeUpdateSeo($action),
                self::ACTION_UPDATE_EVENT => $this->executeUpdateEvent($action),
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
            self::ACTION_CREATE_PAGE => $this->validateCreatePage($payload, $organizationId),
            self::ACTION_UPDATE_PAGE => $this->validateUpdatePage($payload, $organizationId),
            self::ACTION_UPDATE_THEME => $this->validateUpdateTheme($payload),
            self::ACTION_UPDATE_SEO => $this->validateUpdateSeo($payload, $organizationId),
            self::ACTION_UPDATE_EVENT => $this->validateUpdateEvent($payload, $organizationId),
            default => throw new ValidationError(['action' => ['Неизвестный тип действия.']]),
        };
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function validateCreatePage(array $p, int $org): array
    {
        $v = validator($p, [
            'title' => ['required', 'string', 'max:500'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'content' => ['nullable', 'string', 'max:60000'],
        ])->validate();

        $slug = $v['slug'] ?? Str::slug((string) $v['title']);
        if ($slug === '') {
            $slug = 'page-' . Str::lower(Str::random(8));
        }
        if (DB::table('pages')->where('organization_id', $org)->where('slug', $slug)->exists()) {
            throw new ValidationError(['slug' => ['Такой адрес страницы уже есть в организации.']]);
        }

        return [
            'title' => $v['title'],
            'slug' => $slug,
            'content' => $v['content'] ?? '',
            'organization_id' => $org,
            'status' => 'draft',
        ];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function validateUpdatePage(array $p, int $org): array
    {
        $v = validator($p, [
            'public_id' => ['required', 'string', 'max:64'],
            'title' => ['sometimes', 'string', 'max:500'],
            'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'content' => ['sometimes', 'string', 'max:60000'],
        ])->validate();

        $page = DB::table('pages')->where('organization_id', $org)->where('public_id', $v['public_id'])->first();
        if ($page === null) {
            throw new ValidationError(['public_id' => ['Страница не найдена в вашей организации.']]);
        }
        if (isset($v['slug']) && DB::table('pages')
            ->where('organization_id', $org)->where('slug', $v['slug'])
            ->where('id', '!=', (int) $page->id)->exists()) {
            throw new ValidationError(['slug' => ['Такой адрес уже занят.']]);
        }

        // Only fields actually present may be applied; the snapshot is frozen.
        return array_intersect_key($v, array_flip(['public_id', 'title', 'slug', 'content'])) + ['organization_id' => $org];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function validateUpdateTheme(array $p): array
    {
        $v = validator($p, [
            'config' => ['required', 'array', 'min:1', 'max:100'],
        ])->validate();

        foreach (array_keys($v['config']) as $key) {
            if (! is_string($key) || ! str_starts_with($key, 'theme')) {
                throw new ValidationError(['config' => ['Разрешены только ключи с префиксом theme.']]);
            }
        }

        return ['config' => $v['config']];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function validateUpdateSeo(array $p, int $org): array
    {
        $v = validator($p, [
            'entity_type' => ['required', 'string', 'in:page,event'],
            'entity_id' => ['required', 'integer', 'min:1'],
            'locale' => ['sometimes', 'string', 'max:10'],
            'title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'canonical_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'og_image' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ])->validate();

        $table = $v['entity_type'] === 'page' ? 'pages' : 'events';
        $exists = DB::table($table)
            ->where('id', $v['entity_id'])->where('organization_id', $org)->exists();
        if (! $exists) {
            throw new ValidationError(['entity_id' => ['Объект не найден в вашей организации.']]);
        }

        $v['locale'] = $v['locale'] ?? 'ru';

        return $v;
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function validateUpdateEvent(array $p, int $org): array
    {
        $v = validator($p, [
            'public_id' => ['required', 'string', 'max:64'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:60000'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'timezone' => ['sometimes', 'string', 'max:64'],
        ])->validate();

        $eventId = DB::table('events')->where('organization_id', $org)
            ->where('public_id', $v['public_id'])->value('id');
        if ($eventId === null) {
            throw new ValidationError(['public_id' => ['Мероприятие не найдено в вашей организации.']]);
        }
        $v['id'] = (int) $eventId;

        return $v;
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
            'venue_id' => ['bail', 'required', 'integer', 'exists:venues,id'],
            'schema_version_id' => ['bail', 'required', 'integer', 'exists:hall_schema_versions,id'],
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
    private function executeCreatePage(AgentAction $action): array
    {
        $p = $action->payload_json;
        $publicId = \Nabilet\Modules\Core\Support\PublicId::generate();
        $now = now();
        DB::table('pages')->insert([
            'public_id' => $publicId,
            'organization_id' => $p['organization_id'],
            'title' => $p['title'],
            'slug' => $p['slug'],
            'content' => $p['content'] ?? '',
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'page_public_id' => $publicId,
            'slug' => $p['slug'],
            'status' => 'draft',
            'message' => 'Черновик страницы создан; публикацию выполняет администратор.',
        ];
    }

    /** @return array<string, mixed> */
    private function executeUpdatePage(AgentAction $action): array
    {
        $p = $action->payload_json;
        $fields = array_intersect_key($p, array_flip(['title', 'slug', 'content']));
        $fields['updated_at'] = now();
        DB::table('pages')->where('public_id', $p['public_id'])
            ->where('organization_id', $p['organization_id'])->update($fields);

        return ['page_public_id' => $p['public_id'], 'updated_fields' => array_keys($fields)];
    }

    /** @return array<string, mixed> */
    private function executeUpdateTheme(AgentAction $action, object $user): array
    {
        $p = $action->payload_json;
        $orgId = (int) $action->organization_id;
        $row = DB::table('storefront_settings')->where('organization_id', $orgId)->first(['config']);
        $config = $row === null ? [] : (json_decode((string) $row->config, true) ?: []);
        foreach ($p['config'] as $key => $value) {
            $config[$key] = $value;
        }

        $values = [
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'updated_by' => (int) $user->getAuthIdentifier(),
            'updated_at' => now(),
        ];
        $existing = DB::table('storefront_settings')->where('organization_id', $orgId)->value('id');
        if ($existing === null) {
            DB::table('storefront_settings')->insert(['organization_id' => $orgId] + $values);
        } else {
            DB::table('storefront_settings')->where('id', $existing)->update($values);
        }

        return ['updated_keys' => array_keys($p['config']), 'message' => 'Настройки темы обновлены.'];
    }

    /** @return array<string, mixed> */
    private function executeUpdateSeo(AgentAction $action): array
    {
        $p = $action->payload_json;
        $values = [];
        foreach (['title', 'description', 'canonical_url', 'og_image'] as $field) {
            if (array_key_exists($field, $p)) {
                $values[$field] = $p[$field];
            }
        }
        $keys = [
            'entity_type' => $p['entity_type'],
            'entity_id' => $p['entity_id'],
            'locale' => $p['locale'],
        ];
        $existing = DB::table('seo_meta')->where($keys)->value('id');
        if ($existing === null) {
            DB::table('seo_meta')->insert($keys + $values);
        } else {
            DB::table('seo_meta')->where('id', $existing)->update($values);
        }

        return [
            'entity_type' => $p['entity_type'],
            'entity_id' => $p['entity_id'],
            'locale' => $p['locale'],
            'updated_fields' => array_keys($values),
        ];
    }

    /** @return array<string, mixed> */
    private function executeUpdateEvent(AgentAction $action): array
    {
        $p = $action->payload_json;
        /** @var Event $event */
        $event = $this->tenant->withoutScope(fn (): Event => Event::query()->findOrFail($p['id']));
        $fields = array_intersect_key($p, array_flip([
            'title', 'description', 'short_description', 'starts_at', 'ends_at', 'timezone',
        ]));
        $updated = $this->events->update($event, $fields);

        return ['event_public_id' => $updated->public_id, 'updated_fields' => array_keys($fields)];
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
