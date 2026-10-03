<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $session_token_hash
 * @property string|null $device_name
 * @property string|null $user_agent
 * @property string|null $ip_address
 * @property \Carbon\Carbon|null $last_seen_at
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon $created_at
 */
class UserSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'session_token_hash',
        'device_name',
        'user_agent',
        'ip_address',
        'last_seen_at',
        'expires_at',
        'created_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

        protected static function boot(): void
        {
            parent::boot();
            static::creating(function (self $model) {
                if (empty($model->public_id)) {
                    $model->public_id = (string) \Illuminate\Support\Str::ulid()->toBase32();
                }
            });
        }

}
