<?php

declare(strict_types=1);

namespace NabileT\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use NabileT\Modules\Core\Models\Organization;

/**
 * @property int $id
 * @property string $name
 * @property int $organization_id
 * @property string $status 'draft' | 'running' | 'completed'
 * @property \Carbon\CarbonImmutable $started_at
 * @property \Carbon\CarbonImmutable|null $ended_at
 */
class AbExperiment extends Model
{
    protected $table = 'ab_experiments';

    protected $fillable = [
        'name',
        'organization_id',
        'status',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(AbVariant::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AbAssignment::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(AbMetric::class);
    }
}
