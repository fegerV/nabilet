<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $anonymous_id
 * @property string $consent_type
 * @property string $status
 * @property string|null $policy_version
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon\Carbon $created_at
 */
class Consent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'anonymous_id',
        'consent_type',
        'status',
        'policy_version',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
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
