<?php

declare(strict_types=1);

namespace NabileT\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NabileT\Modules\Core\Models\User;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type 'access' | 'deletion' | 'export'
 * @property string $status 'pending' | 'completed' | 'rejected'
 * @property string|null $reason
 * @property \Carbon\CarbonImmutable $created_at
 */
class PrivacyRequest extends Model
{
    protected $table = 'privacy_requests';

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
