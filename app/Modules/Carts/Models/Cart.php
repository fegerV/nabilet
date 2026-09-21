<?php

declare(strict_types=1);

namespace NabileT\Modules\Carts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Core\Users\Models\User;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $session_id
 * @property string $status 'active' | 'abandoned' | 'converted'
 * @property string $currency
 * @property string $total_amount
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class Cart extends Model
{
    protected $table = 'carts';

    protected $fillable = [
        'user_id',
        'session_id',
        'status',
        'currency',
        'total_amount',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'total_amount' => 'string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
