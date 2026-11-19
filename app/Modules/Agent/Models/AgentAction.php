<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Models;

use Illuminate\Database\Eloquent\Model;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

/**
 * Отложенное действие агента (см. миграцию 2026_11_19_220000).
 *
 * Модель намеренно тонкая: вся бизнес-логика — в AgentActionService, чтобы
 * нельзя было «просто переключить статус» мимо проверок организации,
 * способности и человека, одобрившего действие.
 */
final class AgentAction extends Model
{
    use HasPublicId;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    protected $table = 'agent_actions';

    protected $guarded = [];

    protected $casts = [
        'payload_json' => 'array',
        'result_json' => 'array',
        'expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
    ];
}
