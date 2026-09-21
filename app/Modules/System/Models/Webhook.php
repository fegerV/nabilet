<?php

declare(strict_types=1);

namespace NabileT\Modules\System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\Core\Organizations\Models\Organization;

/**
 * @property int $id
 * @property string $name
 * @property int $organization_id
 * @property string $url
 * @property string $secret
 * @property array $events
 * @property bool $is_active
 * @property \Carbon\CarbonImmutable $created_at
 */
class Webhook extends Model
{
    protected $table = 'webhooks';

    protected $fillable = [
        'name',
        'organization_id',
        'url',
        'secret',
        'events',
        'is_active',
    ];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
