<?php

declare(strict_types=1);

namespace NabileT\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ab_experiment_id
 * @property string $name
 * @property string $goal_type 'conversion' | 'revenue' | 'engagement'
 * @property array $configuration
 */
class AbMetric extends Model
{
    protected $table = 'ab_metrics';

    protected $fillable = [
        'ab_experiment_id',
        'name',
        'goal_type',
        'configuration',
    ];

    protected $casts = [
        'configuration' => 'array',
    ];

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AbExperiment::class, 'ab_experiment_id');
    }
}
