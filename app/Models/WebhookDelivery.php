<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $webhook_id
 * @property string $delivery_id
 * @property string $event_name
 * @property array $payload_json
 * @property int|null $status_code
 * @property int $attempt
 * @property string|null $response_body
 * @property string|null $error_message
 * @property \Carbon\Carbon|null $next_retry_at
 * @property \Carbon\Carbon|null $delivered_at
 * @property \Carbon\Carbon $created_at
 */
class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';

    /**
     * В схеме есть только `created_at` — колонки `updated_at` нет. С включёнными
     * таймстемпами Eloquent добавил бы `updated_at` в INSERT и уронил запись
     * на несуществующей колонке.
     */
    public $timestamps = false;

    protected $fillable = [
        'webhook_id',
        'delivery_id',
        'event_name',
        'payload_json',
        'status_code',
        'attempt',
        'response_body',
        'error_message',
        'next_retry_at',
        'delivered_at',
        'created_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'status_code' => 'integer',
        'attempt' => 'integer',
        'next_retry_at' => 'datetime',
        'delivered_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
