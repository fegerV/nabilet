<?php

declare(strict_types=1);

namespace NabileT\Modules\System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $webhook_id
 * @property int $webhook_event_id
 * @property string $status 'pending' | 'success' | 'failed'
 * @property int $attempt_count
 * @property string|null $response_code
 * @property string|null $response_body
 * @property \Carbon\CarbonImmutable $created_at
 */
class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'webhook_id',
        'webhook_event_id',
        'status',
        'attempt_count',
        'response_code',
        'response_body',
    ];

    protected $casts = [
        'attempt_count' => 'integer',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'webhook_event_id');
    }
}
