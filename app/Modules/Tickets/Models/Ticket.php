<?php

declare(strict_types=1);

namespace NabileT\Modules\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NabileT\Modules\Orders\Models\OrderItem;

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
        'order_item_id',
        'status',
        'qr_code',
        'pdf_url',
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
