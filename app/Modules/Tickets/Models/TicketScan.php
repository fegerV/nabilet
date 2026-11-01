<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

/**
 * @property int $id
 * @property string $public_id
 * @property int $ticket_id
 * @property int $session_id
 * @property int|null $device_id
 * @property string|null $client_scan_id
 * @property string $mode 'online' | 'offline_sync'
 * @property string $result admitted|already_used|revoked|... (ScanOutcome)
 * @property \Carbon\Carbon $scanned_at
 */
class TicketScan extends Model
{
    use TenantScoped;
    use HasPublicId;

    protected $table = 'ticket_scans';

    /**
     * Глобальный читающий скоуп (пятая модель после Order, Payment, Ticket и
     * Refund).
     *
     * Существующие пути чтения уже живут в нужном режиме и остаются в нём:
     *   - запись и анализ скана (`TicketScanService::scan`/`scanFromBundle`)
     *     идут под токеном устройства в bypass — арендатор проверяется
     *     `belongsToOrganization` по билету;
     *   - eager-load `scans` у билетов списка/карточки — дети уже
     *     авторизованных билетов (`TicketController::show`/`history`,
     *     `index` целиком в bypass);
     *   - журнал сканов (`CheckinScanReportController`) читается под
     *     контекстом сотрудника И своим явным фильтром по организации заказа
     *     билета — скоуп лишь добавляет фильтр по собственной колонке.
     * Скан не адресуется в маршрутах напрямую — route-binding не нужен.
     * HTTP-запрос без организации здесь означает ошибку, а не «показать все
     * сканы».
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    /** ticket_scans has no updated_at column in the spec schema. */
    public $timestamps = false;

    protected $fillable = [
        'public_id',
        'ticket_id',
        'session_id',
        'device_id',
        'client_scan_id',
        'mode',
        'result',
        'latitude',
        'longitude',
        'metadata_json',
        'scanned_at',
        'created_at',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Tickets\Models\CheckinDevice::class, 'device_id');
    }
}
