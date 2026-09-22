<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property string $channel
 * @property string $type
 * @property string|null $recipient
 * @property string $status
 * @property string|null $provider_message_id
 * @property array|null $payload_json
 * @property \Carbon\Carbon|null $sent_at
 * @property string|null $error_message
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Notification extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'channel',
        'type',
        'recipient',
        'status',
        'provider_message_id',
        'payload_json',
        'sent_at',
        'error_message',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
