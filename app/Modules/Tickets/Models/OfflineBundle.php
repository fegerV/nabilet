<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Organizations\Models\Organization;
use Nabilet\Modules\Sessions\Models\Session;

/** Eloquent mapping for the signed, device-bound offline ticket bundle. */
class OfflineBundle extends Model
{
    protected $table = 'offline_bundles';

    protected $fillable = [
        'public_id',
        'organization_id',
        'checkin_device_id',
        'event_id',
        'session_id',
        'bundle_hash',
        'schema_version',
        'public_key_fingerprint',
        'ticket_count',
        'revoked_count',
        'payload_json',
        'status',
        'generated_at',
        'downloaded_at',
        'expires_at',
    ];

    protected $casts = [
        'schema_version' => 'integer',
        'ticket_count' => 'integer',
        'revoked_count' => 'integer',
        'payload_json' => 'array',
        'generated_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'expires_at' => 'datetime',
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

    public function checkinDevice(): BelongsTo
    {
        return $this->belongsTo(CheckinDevice::class, 'checkin_device_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
}
