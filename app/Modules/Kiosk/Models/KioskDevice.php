<?php

declare(strict_types=1);

namespace Nabilet\Modules\Kiosk\Models;

use Illuminate\Database\Eloquent\Model;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;
use Nabilet\Modules\Core\Tenancy\TenantScoped;
use Nabilet\Modules\Kiosk\Domain\KioskDevice as DevicePolicy;

/**
 * Информационный киоск.
 *
 * Глобальный читающий скоуп ВКЛЮЧЁН: как и у чекера, все системные пути
 * (middleware аутентификации, heartbeats, обновления) обязаны искать устройство
 * через `OrganizationContext::withoutScope()`. Админские запросы идут под
 * контекстом организации администратора, поэтому чужие киоски просто не
 * попадут в выборку — fail-closed.
 *
 * Сырой токен в базе не хранится: только `kiosk_token_hash` (sha256).
 */
class KioskDevice extends Model
{
    use HasPublicId;
    use TenantScoped;

    protected $table = 'kiosk_devices';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'name',
        'location_address',
        'location_note',
        'status',
        'sales_enabled',
        'kiosk_token_hash',
        'cart_token',
        'platform',
        'app_version',
        'config_version',
        'target_version',
        'update_url',
        'update_checksum',
        'update_status',
        'update_applied_version',
        'update_error',
        'update_issued_at',
        'update_finished_at',
        'last_seen_at',
        'last_used_at',
        'revoked_at',
    ];

    /**
     * `cart_token` — живой capability-токен корзины (не хеш). Ни в списке,
     * ни в карточке его отдавать нельзя: это равносильно выдаче секрета.
     * Продажи используют в качестве токена корзины client_sale_id, поэтому
     * колонка сохранена только для совместимости и наружу не попадает.
     */
    protected $hidden = ['cart_token'];

    protected $casts = [
        'sales_enabled' => 'bool',
        'last_seen_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'update_issued_at' => 'datetime',
        'update_finished_at' => 'datetime',
    ];

    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    public function isActive(): bool
    {
        return $this->status === DevicePolicy::STATUS_ACTIVE;
    }

    public function maySell(): bool
    {
        return $this->isActive() && (bool) $this->sales_enabled;
    }
}
