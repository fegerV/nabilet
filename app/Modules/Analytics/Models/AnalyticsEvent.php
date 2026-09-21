<?php

declare(strict_types=1);

namespace Nabilet\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * @property int $id
 * @property string $event_type
 * @property array $properties
 * @property int|null $user_id
 * @property string|null $session_id
 * @property \Carbon\CarbonImmutable $created_at
 */
class AnalyticsEvent extends Model
{
    protected $table = 'analytics_events';

    protected $fillable = [
        'event_type',
        'properties',
        'user_id',
        'session_id',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
