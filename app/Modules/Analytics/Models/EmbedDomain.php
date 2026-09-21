<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Core\Organizations\Models\Organization;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $domain
 * @property bool $active
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class EmbedDomain extends Model
{
    protected $table = 'embed_domains';

    protected $fillable = [
        'organization_id',
        'domain',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'created_at' => 'datetime:Y-m-d H:i:s.u',
        'updated_at' => 'datetime:Y-m-d H:i:s.u',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
