<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Repositories;

use Nabilet\Modules\Tickets\Domain\ScanMode;
use Nabilet\Modules\Tickets\Domain\ScanOutcome;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Models\TicketScan;
use Nabilet\Modules\Tickets\StateMachines\TicketStateMachine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Nabilet\Modules\Core\Support\PublicId;

class TicketRepository
{
    public function __construct(
        protected Ticket $model
    ) {}

    /**
     * Билет по внутреннему id строго в пределах организации.
     *
     * Организация обязательна: без неё чужой билет (по `order.organization_id`)
     * стал бы виден. Для системных контекстов (вебхуки, задания) есть
     * `findAny()`.
     */
    public function find(int $id, int $organizationId): ?Ticket
    {
        // tickets carries session_id/event_id directly (nabilet_core_spec), so
        // there is no `inventoryItem` relation on Ticket — eager-loading one
        // that does not exist crashed every lookup.
        return $this->model->with(['order.items.inventoryItem', 'scans'])
            ->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
            ->find($id);
    }

    /**
     * Билет без скоупа организации — только для системных контекстов.
     */
    public function findAny(int $id): ?Ticket
    {
        return $this->model->with(['order.items.inventoryItem', 'scans'])->find($id);
    }

    public function findByPublicId(string $publicId, int $organizationId): ?Ticket
    {
        return $this->model->where('public_id', $publicId)
            ->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
            ->with(['order.items.inventoryItem', 'scans'])
            ->first();
    }

    public function findAnyByPublicId(string $publicId): ?Ticket
    {
        return $this->model->where('public_id', $publicId)
            ->with(['order.items.inventoryItem', 'scans'])
            ->first();
    }

    public function findByOrder(int $orderId): Collection
    {
        return $this->model->where('order_id', $orderId)
            ->with(['orderItem.inventoryItem', 'scans'])
            ->get();
    }

    public function findBySession(int $sessionId, int $limit = 50): LengthAwarePaginator
    {
        // tickets.session_id is a first-class column; filtering through
        // whereHas('order') was wrong for orders without a denormalized session_id.
        return $this->model->where('session_id', $sessionId)
            ->with(['order.user', 'orderItem.inventoryItem'])
            ->paginate($limit);
    }

    public function create(array $data): Ticket
    {
        return $this->model->create($data);
    }

    public function update(Ticket $ticket, array $data): Ticket
    {
        $ticket->update($data);
        return $ticket->fresh();
    }

    /**
     * Revoke a ticket (§44). The spec status machine is
     * issued|used|cancelled|refunded|expired|revoked — there is no
     * `invalidated` state, and the columns `invalidated_at` /
     * `invalidation_reason` do not exist in the tickets table (writing them
     * produced SQLSTATE 42S22). The correct pair is `revoked_at` /
     * `revoked_reason`.
     */
    public function revoke(Ticket $ticket, string $reason): Ticket
    {
        $ticket->update([
            'status' => TicketStateMachine::REVOKED,
            'revoked_at' => now(),
            'revoked_reason' => mb_substr($reason, 0, 100),
        ]);

        return $ticket->fresh();
    }

    /** Back-compatible alias for older callers. Prefer revoke(). */
    public function invalidate(Ticket $ticket, string $reason): Ticket
    {
        return $this->revoke($ticket, $reason);
    }

    /**
     * Record a scan row. ticket_scans requires public_id, session_id and mode
     * (NOT NULL per spec) — the old implementation invented non-existent
     * columns (`checkin_device_id`, `location`, `success`) and omitted the
     * required ones, so every check-in insert failed.
     */
    public function recordScan(
        Ticket $ticket,
        ?int $deviceId,
        string $result = ScanOutcome::ADMITTED,
        string $mode = ScanMode::ONLINE,
        ?string $clientScanId = null,
        ?array $metadata = null,
        ?\DateTimeImmutable $scannedAt = null,
    ): TicketScan {
        return $ticket->scans()->create([
            'public_id' => PublicId::generate(),
            'session_id' => $ticket->session_id,
            'device_id' => $deviceId,
            'client_scan_id' => $clientScanId,
            'mode' => $mode,
            'result' => $result,
            // Момент СКАНИРОВАНИЯ, а не записи. Он различается ровно тогда,
            // когда устройство прислало офлайн-пакет: сканирование было в
            // 19:02, а запись — в 23:40. Подставлять `now()` молча значило бы
            // врать в аудите входа о времени прохода.
            'scanned_at' => $scannedAt === null ? now() : \Carbon\Carbon::instance($scannedAt),
            // `ticket_scans` по спецификации НЕТ колонки `updated_at` (поэтому у
            // модели `$timestamps = false`), а `created_at` — NOT NULL без
            // дефолта. Eloquent его не подставит, поэтому пишем вручную:
            // это время ВСТАВКИ строки, оно отличается от `scanned_at` ровно у
            // офлайн-пакетов и не должно совпадать с моментом прохода.
            'created_at' => now(),
            'metadata_json' => $metadata,
        ]);
    }

    public function getScanCount(Ticket $ticket): int
    {
        return $ticket->scans()->count();
    }

    public function findValidTicketsBySession(int $sessionId): Collection
    {
        return $this->model->where('session_id', $sessionId)
            ->whereIn('status', [TicketStateMachine::ISSUED, TicketStateMachine::USED])
            ->with(['order.user', 'orderItem.inventoryItem'])
            ->get();
    }

    public function getCheckinStats(int $sessionId): array
    {
        $totalTickets = $this->model->where('session_id', $sessionId)
            ->whereIn('status', [TicketStateMachine::ISSUED, TicketStateMachine::USED])
            ->count();

        $used = $this->model->where('session_id', $sessionId)
            ->where('status', TicketStateMachine::USED)
            ->count();

        return [
            'total' => $totalTickets,
            'used' => $used,
            'remaining' => $totalTickets - $used,
        ];
    }
}
