<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $ticket_id
 * @property int $session_id
 * @property int|null $device_id
 * @property string|null $client_scan_id
 * @property string $mode 'online' | 'offline_sync'
 * @property string $result admitted|already_used|revoked|... (ScanOutcome)
 * @property \Carbon\Carbon $scanned_at
 */
class TicketScan extends Model
{
    protected $table = 'ticket_scans';

    /** ticket_scans has no updated_at column in the spec schema. */
    public $timestamps = false;

    protected $fillable = [
        'public_id',
        'ticket_id',
        'session_id',
        'device_id',
        'client_scan_id',
        'mode',
        'result',
        'latitude',
        'longitude',
        'metadata_json',
        'scanned_at',
        'created_at',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Tickets\Models\CheckinDevice::class, 'device_id');
    }
}
