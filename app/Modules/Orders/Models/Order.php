<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\Core\Users\Models\User;
use App\Modules\Core\Organizations\Models\Organization;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int|null $user_id
 * @property string $status 'pending' | 'confirmed' | 'cancelled' | 'refunded'
 * @property string $currency
 * @property string $subtotal
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $total_amount
 * @property string|null $metadata
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class Order extends Model
{
    protected $table = 'orders';

    protected $fillable = [
        'public_id',
        'organization_id',
        'user_id',
        'status',
        'currency',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'metadata',
    ];

    protected $casts = [
        'subtotal' => 'string',
        'discount_amount' => 'string',
        'tax_amount' => 'string',
        'total_amount' => 'string',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
