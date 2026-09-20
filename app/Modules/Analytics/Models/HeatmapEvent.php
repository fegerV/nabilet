<?php

declare(strict_types=1);

namespace NabileT\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NabileT\Modules\Core\Models\User;

/**
 * @property int $id
 * @property string $event_type
 * @property array $properties
 * @property int|null $user_id
 * @property string $page_url
 * @property int $scroll_depth
 * @property \Carbon\CarbonImmutable $created_at
 */
class HeatmapEvent extends Model
{
    protected $table = 'heatmap_events';

    protected $fillable = [
        'event_type',
        'properties',
        'user_id',
        'page_url',
        'scroll_depth',
    ];

    protected $casts = [
        'properties' => 'array',
        'scroll_depth' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
