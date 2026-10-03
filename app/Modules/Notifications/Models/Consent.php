<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * @property int $id
 * @property int $user_id
 * @property string $channel
 * @property bool $is_subscribed
 * @property \Carbon\CarbonImmutable $created_at
 */
class Consent extends Model
{
    protected $table = 'consents';

    protected $fillable = [
        'user_id',
        'channel',
        'is_subscribed',
    ];

    protected $casts = [
        'is_subscribed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
