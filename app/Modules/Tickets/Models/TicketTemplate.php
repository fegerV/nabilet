<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Nabilet\Modules\Organizations\Models\Organization;

/**
 * Eloquent mapping for `ticket_templates` (005_payments_tickets).
 * Schema columns are organization_id, name, format, width, height,
 * template_json, active and public_id — not session_id/qr_prefix/is_active.
 */
class TicketTemplate extends Model
{
    protected $table = 'ticket_templates';

    protected $fillable = [
        'public_id',
        'organization_id',
        'name',
        'format',
        'width',
        'height',
        'template_json',
        'active',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'template_json' => 'array',
        'active' => 'boolean',
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
}
