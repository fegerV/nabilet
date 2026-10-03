<?php

declare(strict_types=1);

namespace Nabilet\Modules\Sessions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Session Model (Event Session)
 */
class Session extends Model
{
    protected $table = 'sessions';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'event_id',
        'venue_id',
        'hall_id',
        'schema_version_id',
        'starts_at',
        'ends_at',
        'sales_start_at',
        'sales_end_at',
        'timezone',
        'status',
    ];

    /** Конвенция проекта: char(26) public_id NOT NULL → генерим ULID при создании. */
    protected static function booted(): void
    {
        static::creating(function (Session $session): void {
            if ($session->public_id === null) {
                $session->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime:Y-m-d H:i:s.u',
            'ends_at' => 'datetime:Y-m-d H:i:s.u',
            'sales_start_at' => 'datetime:Y-m-d H:i:s.u',
            'sales_end_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Events\Models\Event::class);
    }

    /**
     * Найти сессию по числовому id или public_id.
     * Покупательский API отдаёт числовой id сессии; CartService хранит
     * public_id в cart.session_id. Метод покрывает оба формата.
     */
    public static function findOrFailBySessionId(string|int $sessionId): self
    {
        $query = static::query()->where(function ($q) use ($sessionId) {
            $q->where('id', $sessionId)->orWhere('public_id', $sessionId);
        });

        return $query->firstOrFail();
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Venues\Models\Venue::class);
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Venues\Models\Hall::class);
    }

    /**
     * Sales gate: the operational switch (`status = on_sale`) AND the time window
     * (`sales_start_at` / `sales_end_at`) must both permit selling right now.
     *
     * The two gates are independent by design (ТЗ §13): the window is scheduled,
     * `on_sale` is the organizer's emergency stop. A session in any other status
     * (draft, scheduled, sold_out, closed, completed, cancelled) never sells —
     * see SessionStateMachine::sellable().
     */
    public function isSellableAt(?\DateTimeInterface $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now');

        if (!in_array($this->status, \Nabilet\Modules\Sessions\StateMachines\SessionStateMachine::sellable(), true)) {
            return false;
        }

        if ($this->sales_start_at !== null && $now < $this->sales_start_at) {
            return false;
        }

        if ($this->sales_end_at !== null && $now > $this->sales_end_at) {
            return false;
        }

        return true;
    }

    /**
     * Human-readable reason why the session is not sellable, for error messages.
     */
    public function salesBlockReason(?\DateTimeInterface $now = null): string
    {
        $now ??= new \DateTimeImmutable('now');

        if (!in_array($this->status, \Nabilet\Modules\Sessions\StateMachines\SessionStateMachine::sellable(), true)) {
            return sprintf('session status is "%s"; tickets are sold only while the session is "on_sale".', $this->status);
        }

        if ($this->sales_start_at !== null && $now < $this->sales_start_at) {
            return sprintf('sales open at %s.', $this->sales_start_at->format(\DateTimeInterface::ATOM));
        }

        if ($this->sales_end_at !== null && $now > $this->sales_end_at) {
            return sprintf('sales closed at %s.', $this->sales_end_at->format(\DateTimeInterface::ATOM));
        }

        return 'sales are open.';
    }

    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Venues\Models\HallSchemaVersion::class, 'schema_version_id');
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Inventory\Models\InventoryItem::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Cart\Models\Cart::class);
    }

    public function seatHolds(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Orders\Models\SeatHold::class);
    }

    public function ticketScans(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Tickets\Models\TicketScan::class);
    }
}
