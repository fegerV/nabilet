<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Models;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
// Каноническая модель. Дубликат в `Modules\Organizations\Models\Organization`
// нёс трейт `SoftDeletes` при отсутствующей колонке `organizations.deleted_at`,
// из-за чего эта связь падала с `SQLSTATE[42S22]` при первой же загрузке.
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Core\Tenancy\TenantScoped;

/** Eloquent mapping for `checkin_devices`; the raw device token is never stored. */
class CheckinDevice extends Model
{
    use HasPublicId;
    use TenantScoped;

    protected $table = 'checkin_devices';

    /**
     * Глобальный читающий скоуп (шестая модель после Order, Payment, Ticket,
     * Refund и TicketScan).
     *
     * Трейт подключён тем же шагом: до него модель не использовала
     * `TenantScoped` вовсе. Единственный писатель (`DeviceTokenIssuer::issue`)
     * передавал `organization_id` сам, но без защиты от записи чужому
     * тенанту; проверку совпадения с контекстом теперь делает трейт.
     *
     * Существующие пути чтения уже живут в нужном режиме и остаются в нём:
     *   - аутентификация чекера (`AuthenticateChecker`) и двойная
     *     идентификация бандла (`OfflineBundleController::deviceIdOrStaff`)
     *     ищут устройство по хешу токена В bypass — токен и есть capability,
     *     активный арендатор ставится из найденного устройства следом;
     *   - админские список/резолв (`CheckinDeviceController`,
     *     `OfflineBundleController::issue`) ходят под контекстом сотрудника;
     *   - проверка принадлежности устройства сотруднику
     *     (`CheckinController::deviceId`) фильтрует по своей организации явно.
     * HTTP-запрос без организации здесь означает ошибку, а не «показать все
     * устройства».
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected $fillable = [
        'public_id',
        'organization_id',
        'name',
        'device_token_hash',
        'platform',
        'app_version',
        'status',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(TicketScan::class, 'device_id');
    }

    public function offlineBundles(): HasMany
    {
        return $this->hasMany(OfflineBundle::class, 'checkin_device_id');
    }
}
