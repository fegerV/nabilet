<?php

declare(strict_types=1);

namespace Nabilet\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * @property int $id
 * @property int $ab_experiment_id
 * @property int|null $user_id
 * @property string $visitor_id
 * @property int $ab_variant_id
 * @property \Carbon\CarbonImmutable $created_at
 */
class AbAssignment extends Model
{
    protected $table = 'ab_assignments';

    protected $fillable = [
        'ab_experiment_id',
        'user_id',
        'visitor_id',
        'ab_variant_id',
    ];

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AbExperiment::class, 'ab_experiment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(AbVariant::class, 'ab_variant_id');
    }
}
