<?php

declare(strict_types=1);

namespace App\Modules\System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Core\Organizations\Models\Organization;

/**
 * @property int $id
 * @property string $name
 * @property int $organization_id
 * @property string $token_hash
 * @property array $abilities
 * @property \Carbon\CarbonImmutable $last_used_at
 * @property \Carbon\CarbonImmutable $created_at
 */
class ApiKey extends Model
{
    protected $table = 'api_keys';

    protected $fillable = [
        'name',
        'organization_id',
        'token_hash',
        'abilities',
        'last_used_at',
    ];

    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
