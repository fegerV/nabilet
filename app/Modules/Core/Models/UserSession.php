<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserSession Model
 */
class UserSession extends Model
{
    protected $table = 'user_sessions';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'session_token_hash',
        'device_name',
        'user_agent',
        'ip_address',
        'last_seen_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime:Y-m-d H:i:s.u',
            'expires_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
