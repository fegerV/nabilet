<?php

declare(strict_types=1);

namespace NabileT\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Core\Users\Models\User;

/**
 * @property int $id
 * @property int $notification_template_id
 * @property int $user_id
 * @property string $channel
 * @property string $status 'pending' | 'sent' | 'failed'
 * @property array $payload
 * @property \Carbon\CarbonImmutable $created_at
 */
class Notification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'notification_template_id',
        'user_id',
        'channel',
        'status',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
