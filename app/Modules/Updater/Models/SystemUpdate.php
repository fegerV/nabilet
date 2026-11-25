<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Models;

use Illuminate\Database\Eloquent\Model;
use Nabilet\Modules\Updater\Domain\UpdateStatus;

/**
 * Одна попытка обновления системы.
 *
 * Модель намеренно НЕ использует `TenantScoped`: код обновляется для всех
 * организаций сразу, и привязка записи к текущей организации сделала бы журнал
 * неполным в multi-tenant установке. Доступ — только через админские эндпоинты
 * с `auth:api` + `admin` + `EnsurePlatformAdmin`.
 */
class SystemUpdate extends Model
{
    public const SOURCE_FEED = 'feed';
    public const SOURCE_UPLOAD = 'upload';

    protected $table = 'system_updates';

    protected $fillable = [
        'from_version',
        'to_version',
        'source',
        'status',
        'step',
        'artifact',
        'artifact_sha256',
        'backup_status',
        'actor_id',
        'error',
        'locked_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return UpdateStatus::isActive((string) $this->status);
    }

    public function isTerminal(): bool
    {
        return UpdateStatus::isTerminal((string) $this->status);
    }

    /** @return array<string, mixed> */
    public function toAdminArray(): array
    {
        return [
            'update_id' => (int) $this->id,
            'status' => (string) $this->status,
            'step' => $this->step === null ? null : (string) $this->step,
            'status_label' => UpdateStatus::label((string) $this->status),
            'current_version' => (string) $this->from_version,
            'target_version' => (string) $this->to_version,
            'source' => (string) $this->source,
            'error' => $this->error === null ? null : (string) $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'can_rollback' => in_array((string) $this->status, [
                UpdateStatus::DONE,
                UpdateStatus::FAILED_AFTER_SWAP,
            ], true),
        ];
    }
}
