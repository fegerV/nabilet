<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nabilet\Modules\Events\Models\Session;

/**
 * @property int $id
 * @property string $name
 * @property int $session_id
 * @property string|null $qr_prefix
 * @property bool $is_active
 * @property \Carbon\CarbonImmutable $created_at
 */
class TicketTemplate extends Model
{
    protected $table = 'ticket_templates';

    protected $fillable = [
        'name',
        'session_id',
        'qr_prefix',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
