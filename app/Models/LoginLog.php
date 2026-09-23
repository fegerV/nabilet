<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $identifier
 * @property bool $success
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $failure_code
 * @property \Carbon\Carbon $created_at
 */
class LoginLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'identifier',
        'success',
        'ip_address',
        'user_agent',
        'failure_code',
        'created_at',
    ];

    protected $casts = [
        'success' => 'boolean',
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
