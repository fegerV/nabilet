<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Models;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
// Каноническая модель. Дубликат в `Modules\Organizations\Models\Organization`
// нёс трейт `SoftDeletes` при отсутствующей колонке `organizations.deleted_at`,
// из-за чего эта связь падала с `SQLSTATE[42S22]` при первой же загрузке.
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Core\Tenancy\TenantScoped;

/** Eloquent mapping for the signed, device-bound offline ticket bundle. */
class OfflineBundle extends Model
{
    use HasPublicId;
    use TenantScoped;

    protected $table = 'offline_bundles';

    /**
     * Глобальный читающий скоуп (седьмая модель после Order, Payment, Ticket,
     * Refund, TicketScan и CheckinDevice).
     *
     * Трейт подключён тем же шагом: до него модель не использовала
     * `TenantScoped` вовсе — единственный писатель
     * (`OfflineBundleService::issue`) передавал `organization_id` сам, без
     * защиты от записи чужому тенанту; совпадение с контекстом теперь
     * проверяет трейт.
     *
     * Существующие пути чтения уже живут в нужном режиме и остаются в нём:
     *   - выпуск бандла (`OfflineBundleService::issue`) идёт под контекстом
     *     устройства/сотрудника: `$device->organization_id` проверен
     *     `assertCanAccess` ещё до выпуска, а idемпотентный повтор ищет бандл
     *     по хешу того же устройства (хеш device-scoped) — контекст не мешает;
     *   - чтение бандла по имени (`OfflineBundleController::show`) резолвится
     *     в bypass ДО того, как токен чекера поставит арендатора в контекст;
     *     доступ решают `assertCanAccess` (сотрудник) и сравнение организации
     *     устройства (чекер) — чужой бандл остаётся 404;
     *   - список бандлов устройства — дети авторизованного устройства, у
     *     сотрудника контекст организации уже стоит.
     * Бандл не адресуется route-binding'ом модели — строковый параметр пути.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected $fillable = [
        'public_id',
        'organization_id',
        'checkin_device_id',
        'event_id',
        'session_id',
        'bundle_hash',
        'schema_version',
        'public_key_fingerprint',
        'ticket_count',
        'revoked_count',
        'payload_json',
        'status',
        'generated_at',
        'downloaded_at',
        'expires_at',
    ];

    protected $casts = [
        'schema_version' => 'integer',
        'ticket_count' => 'integer',
        'revoked_count' => 'integer',
        'payload_json' => 'array',
        'generated_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function checkinDevice(): BelongsTo
    {
        return $this->belongsTo(CheckinDevice::class, 'checkin_device_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
}
