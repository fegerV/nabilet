<?php

declare(strict_types=1);

namespace NabileT\Modules\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NabileT\Modules\Core\Models\User;

/**
 * @property int $id
 * @property string $name
 * @property string $device_token
 * @property bool $is_active
 * @property int|null $last_synced_session_id
 * @property \Carbon\CarbonImmutable $created_at
 */
class CheckinDevice extends Model
{
    protected $table = 'checkin_devices';

    protected $fillable = [
        'name',
        'device_token',
        'is_active',
        'last_synced_session_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function lastSyncedSession(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'last_synced_session_id');
    }
}
