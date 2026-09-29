<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Orders\Models\OrderItem;

/**
 * @property int $id
 * @property string $public_id
 * @property string $ticket_number
 * @property int $order_item_id
 * @property string $status 'issued' | 'checked_in' | 'cancelled' | 'refunded'
 * @property string|null $qr_code
 * @property string|null $pdf_url
 * @property \Carbon\CarbonImmutable $created_at
 */
class Ticket extends Model
{
    protected $table = 'tickets';

    protected $fillable = [
        'public_id',
        'ticket_number',
        'ticket_index',
        'order_id',
        'order_item_id',
        'event_id',
        'session_id',
        'inventory_item_id',
        'seat_id',
        'standing_zone_id',
        'holder_name',
        'status',
        'qr_version',
        'qr_token_hash',
        'qr_payload',
        'qr_code',
        'pdf_url',
        'issued_at',
        'used_at',
        'cancelled_at',
        'refunded_at',
        'expired_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected $casts = [
        'ticket_index' => 'integer',
        'qr_version' => 'integer',
        'issued_at' => 'datetime',
        'used_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at' => 'datetime',
        'expired_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(TicketScan::class);
    }
}
