<?php

declare(strict_types=1);

namespace App\Modules\System\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $value
 * @property \Carbon\CarbonImmutable $expires_at
 */
class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'value',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
