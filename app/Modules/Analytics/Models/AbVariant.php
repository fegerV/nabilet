<?php

declare(strict_types=1);

namespace Nabilet\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ab_experiment_id
 * @property string $name 'control' | 'variant_a' | 'variant_b'
 * @property float $weight
 * @property array $configuration
 */
class AbVariant extends Model
{
    protected $table = 'ab_variants';

    protected $fillable = [
        'ab_experiment_id',
        'name',
        'weight',
        'configuration',
    ];

    protected $casts = [
        'weight' => 'float',
        'configuration' => 'array',
    ];

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AbExperiment::class, 'ab_experiment_id');
    }
}
