<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Nabilet\Modules\Organizations\Models\Organization;

/** Eloquent mapping for `checkin_devices`; the raw device token is never stored. */
class CheckinDevice extends Model
{
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

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = (string) Str::ulid()->toBase32();
            }
        });
    }

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
