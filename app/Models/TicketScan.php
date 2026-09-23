<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $ticket_id
 * @property int $session_id
 * @property int|null $device_id
 * @property string|null $client_scan_id
 * @property string $mode
 * @property string $result
 * @property \Carbon\Carbon $scanned_at
 * @property float|null $latitude
 * @property float|null $longitude
 * @property array|null $metadata_json
 * @property \Carbon\Carbon $created_at
 */
class TicketScan extends Model
{
    protected $fillable = [
        'public_id',
        'ticket_id',
        'session_id',
        'device_id',
        'client_scan_id',
        'mode',
        'result',
        'scanned_at',
        'latitude',
        'longitude',
        'metadata_json',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'metadata_json' => 'array',
        'scanned_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(CheckinDevice::class, 'device_id');
    }

        protected static function boot(): void
        {
            parent::boot();
            static::creating(function (self $model) {
                if (empty($model->public_id)) {
                    $model->public_id = (string) \Illuminate\Support\Str::ulid()->toBase32();
                }
            });
        }

}
