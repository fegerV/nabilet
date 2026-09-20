<?php

declare(strict_types=1);

namespace NabileT\Modules\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ticket_id
 * @property \Carbon\CarbonImmutable $scanned_at
 * @property string $result 'success' | 'invalid' | 'duplicate' | 'expired'
 * @property string|null $device_id
 * @property string|null $message
 */
class TicketScan extends Model
{
    protected $table = 'ticket_scans';

    protected $fillable = [
        'ticket_id',
        'scanned_at',
        'result',
        'device_id',
        'message',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
