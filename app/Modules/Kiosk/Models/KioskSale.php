<?php

declare(strict_types=1);

namespace Nabilet\Modules\Kiosk\Models;

use Illuminate\Database\Eloquent\Model;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;
use Nabilet\Modules\Core\Tenancy\TenantScoped;
use Nabilet\Modules\Kiosk\Domain\KioskSale as SalePolicy;

/**
 * Продажа, инициированная киоском.
 *
 * Идемпотентность — на уровне БД: UNIQUE (kiosk_device_id, client_sale_id).
 * Повторный запрос после обрыва связи возвращает ту же продажу, а не второй
 * билет. `qr_payload` заполняется только после подтверждения оплаты.
 */
class KioskSale extends Model
{
    use HasPublicId;
    use TenantScoped;

    protected $table = 'kiosk_sales';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'kiosk_device_id',
        'client_sale_id',
        'status',
        'order_id',
        'order_public_id',
        'payment_id',
        'amount_minor',
        'currency',
        'customer_email',
        'holder_name',
        'qr_payload',
        'idempotency_key',
        'error_code',
        'error_message',
        'paid_at',
        'issued_at',
    ];

    protected $casts = [
        'amount_minor' => 'int',
        'paid_at' => 'datetime',
        'issued_at' => 'datetime',
    ];

    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    public function kioskDevice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(KioskDevice::class);
    }

    public function isTerminal(): bool
    {
        return SalePolicy::isTerminal((string) $this->status);
    }
}
