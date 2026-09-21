<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Seat Model
 */
class Seat extends Model
{
    protected $table = 'seats';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'row_id',
        'number',
        'label',
        'x',
        'y',
        'width',
        'height',
        'rotation',
        'type',
        'status',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'decimal:3',
            'y' => 'decimal:3',
            'width' => 'decimal:3',
            'height' => 'decimal:3',
            'rotation' => 'decimal:3',
            'metadata_json' => 'array',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(HallRow::class, 'row_id');
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Inventory\Models\InventoryItem::class, 'seat_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Tickets\Models\Ticket::class, 'seat_id');
    }
}
