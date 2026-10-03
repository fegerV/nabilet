<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Models;

use Illuminate\Database\Eloquent\Model;
use Nabilet\Modules\Core\Users\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property string $version
 * @property bool $is_active
 * @property array $config
 * @property \Carbon\CarbonImmutable $created_at
 */
class Module extends Model
{
    protected $table = 'modules';

    protected $fillable = [
        'name',
        'version',
        'is_active',
        'config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'config' => 'array',
    ];
}
