<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Services;

use Carbon\CarbonInterface;
use Nabilet\Modules\Checkin\Domain\BundleTicket;
use Nabilet\Modules\Checkin\Domain\CheckinDevice as DevicePolicy;
use Nabilet\Modules\Checkin\Domain\OfflineBundleBuilder;
use Nabilet\Modules\Checkin\Domain\OfflineBundleRequest;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Tickets\Domain\TicketSnapshot;
use Nabilet\Modules\Tickets\Models\CheckinDevice;
use Nabilet\Modules\Tickets\Models\OfflineBundle;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Services\QrSignerFactory;
use Nabilet\Modules\Tickets\StateMachines\TicketStateMachine;

/**
 * Транспортный слой offline-бандлов: из HTTP-запроса админ-части — строка
 * `offline_bundles`.
 *
 * ДО ЭТОГО БЫЛО
 *   Домен (`OfflineBundleBuilder`, `OfflineBundlePlan`, `BundleTicket`) был
 *   готов и покрыт юнит-тестами, но ни один HTTP-эндпоинт его не вызывал:
 *   Android-чекер не мог получить бандл, а админка — не могла его выдать
 *   (аудит 09.10, №13).
 *
 * ПОЧЕМУ БЕЗ ЛИЧНЫХ ДАННЫХ
 *   В `offline_bundles.payload_json` уходят только public_id, номер и статус
 *   билета (`BundleTicket` физически не умеет носить имя). Бандл лежит на
 *   чужом телефоне — §43/§44 запрещают в нём персональные данные.
 *
 * ПОЧЕМУ ГЛОБАЛЬНЫЙ UNIQUE ПО `bundle_hash` НЕ МЕШАЕТ
 *   Хеш считен от payload, в который уже вмержен `device->publicId`, поэтому
 *   «тот же контент другой розетке» — разные хешы, а «тот же сканер повторно
 *   синхронизируется» — тот же: находим существующую строку и отдаём её, не
 *   ловя 1062 Duplicate entry.
 */
final class OfflineBundleService
{
    public function issue(CheckinDevice $device, Event $event, ?Session $session): OfflineBundle
    {
        $scope = Ticket::query()
            ->where('event_id', $event->id)
            ->when($session !== null, fn ($q) => $q->where('session_id', $session->id));

        /** Snapshots вместо сырых строк: BundleTicket::fromSnapshot() принимает
         * только «факты решения» — иначе в бандл случайно просочится владелец. */
        $toSnapshot = fn (Ticket $ticket): TicketSnapshot => new TicketSnapshot(
            publicId: (string) $ticket->public_id,
            status: (string) $ticket->status,
            sessionId: (int) $ticket->session_id,
            eventId: (int) $ticket->event_id,
            ticketNumber: (string) ($ticket->ticket_number ?? ''),
            usedAt: $ticket->used_at?->toDateTimeImmutable(),
            revokedAt: $ticket->revoked_at?->toDateTimeImmutable(),
            refundedAt: $ticket->refunded_at?->toDateTimeImmutable(),
            cancelledAt: $ticket->cancelled_at?->toDateTimeImmutable(),
        );

        $validTickets = $scope->clone()
            ->where('status', TicketStateMachine::ISSUED)
            ->orderBy('public_id')
            ->get()
            ->map(fn (Ticket $t): BundleTicket => BundleTicket::fromSnapshot($toSnapshot($t)))
            ->all();

        $revokedTickets = $scope->clone()
            ->whereIn('status', [
                TicketStateMachine::USED,
                TicketStateMachine::REVOKED,
                TicketStateMachine::REFUNDED,
                TicketStateMachine::CANCELLED,
            ])
            ->orderBy('public_id')
            ->get()
            ->map(fn (Ticket $t): BundleTicket => BundleTicket::fromSnapshot($toSnapshot($t)))
            ->all();

        $plan = (new OfflineBundleBuilder())->build(new OfflineBundleRequest(
            device: new DevicePolicy(
                id: (int) $device->id,
                publicId: (string) $device->public_id,
                status: (string) $device->status,
            ),
            organizationId: (int) $device->organization_id,
            eventId: (int) $event->id,
            sessionId: $session !== null ? (int) $session->id : null,
            validTickets: $validTickets,
            revokedTickets: $revokedTickets,
            // «Отпечаток ключа» — id ключа подписи QR: устройство офлайн
            // проверяет подпись тем же секретом (§43).
            publicKeyFingerprint: QrSignerFactory::make()->keyId(),
            generatedAt: new \DateTimeImmutable(),
            sessionEndsAt: $session?->ends_at?->toDateTimeImmutable(),
        ));

        $existing = OfflineBundle::query()
            ->where('bundle_hash', $plan->bundleHash)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return OfflineBundle::create([
            'organization_id' => (int) $device->organization_id,
            'checkin_device_id' => (int) $device->id,
            'event_id' => (int) $event->id,
            'session_id' => $session !== null ? (int) $session->id : null,
            'bundle_hash' => $plan->bundleHash,
            'schema_version' => $plan->schemaVersion,
            'public_key_fingerprint' => $plan->publicKeyFingerprint,
            'ticket_count' => $plan->ticketCount,
            'revoked_count' => $plan->revokedCount,
            'payload_json' => $plan->payload,
            'status' => 'generated',
            'generated_at' => $plan->generatedAt,
            'expires_at' => CarbonInterface::instance($plan->expiresAt),
        ]);
    }

    /**
     * Публичные поля бандла для ответа (id — public_id; device/event/session
     * тоже публичные идентификаторы, внутренние BIGINT наружу не уходят).
     *
     * @return array<string, mixed>
     */
    public function present(OfflineBundle $bundle): array
    {
        return [
            'id' => (string) $bundle->public_id,
            'checkin_device_id' => (string) ($bundle->checkinDevice?->public_id ?? ''),
            'event_id' => (string) ($bundle->event?->public_id ?? ''),
            'session_id' => $bundle->session?->public_id !== null
                ? (string) $bundle->session->public_id
                : null,
            'bundle_hash' => (string) $bundle->bundle_hash,
            'schema_version' => (int) $bundle->schema_version,
            'public_key_fingerprint' => $bundle->public_key_fingerprint !== null
                ? (string) $bundle->public_key_fingerprint
                : null,
            'ticket_count' => (int) $bundle->ticket_count,
            'revoked_count' => (int) $bundle->revoked_count,
            'payload_json' => $bundle->payload_json,
            'status' => (string) $bundle->status,
            'generated_at' => $bundle->generated_at?->toIso8601String(),
            'downloaded_at' => $bundle->downloaded_at?->toIso8601String(),
            'expires_at' => $bundle->expires_at?->toIso8601String(),
        ];
    }
}
