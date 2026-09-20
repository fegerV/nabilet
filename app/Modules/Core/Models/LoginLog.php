<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LoginLog Model
 */
class LoginLog extends Model
{
    protected $table = 'login_logs';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'identifier',
        'success',
        'ip_address',
        'user_agent',
        'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
