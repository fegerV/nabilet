<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Models;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
// Каноническая модель. Дубликат в `Modules\Organizations\Models\Organization`
// нёс трейт `SoftDeletes` при отсутствующей колонке `organizations.deleted_at`,
// из-за чего эта связь падала с `SQLSTATE[42S22]` при первой же загрузке.
use Nabilet\Modules\Core\Organizations\Models\Organization;

/** Eloquent mapping for `checkin_devices`; the raw device token is never stored. */
class CheckinDevice extends Model
{
    use HasPublicId;

    protected $table = 'checkin_devices';

    protected $fillable = [
        'public_id',
        'organization_id',
        'name',
        'device_token_hash',
        'platform',
        'app_version',
        'status',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(TicketScan::class, 'device_id');
    }

    public function offlineBundles(): HasMany
    {
        return $this->hasMany(OfflineBundle::class, 'checkin_device_id');
    }
}
