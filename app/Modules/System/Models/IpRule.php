<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $ip_address
 * @property string $rule_type 'allow' | 'deny'
 * @property string|null $reason
 * @property bool $is_active
 * @property \Carbon\CarbonImmutable $created_at
 */
class IpRule extends Model
{
    protected $table = 'ip_rules';

    protected $fillable = [
        'ip_address',
        'rule_type',
        'reason',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
