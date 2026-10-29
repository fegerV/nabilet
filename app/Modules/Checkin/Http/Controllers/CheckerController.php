<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\AuthError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Checkin\Http\Middleware\AuthenticateChecker;
use Nabilet\Modules\Checkin\Http\Requests\CheckinSyncRequest;
use Nabilet\Modules\Checkin\Http\Requests\CheckinUseRequest;
use Nabilet\Modules\Checkin\Http\Requests\CheckinValidateRequest;
use Nabilet\Modules\Checkin\Services\DeviceTokenIssuer;
use Nabilet\Modules\Checkin\Support\CheckinVerdict;
use Nabilet\Modules\Checkin\Support\SessionLocator;
use Nabilet\Modules\Checkin\Support\TicketLocator;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Tickets\Domain\ScanOutcome;
use Nabilet\Modules\Tickets\Models\CheckinDevice;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Services\TicketScanService;

/**
 * Три эндпоинта Android-чекера по контракту (ТЗ §31, §77):
 * `POST /api/v1/checkin/validate|use|sync`.
 *
 * ПОЧЕМУ ЭТО ОТДЕЛЬНЫЙ КОНТРОЛЛЕР, А НЕ `Tickets\...\CheckinController`
 *   Тот закрыт `['auth:api', 'admin']` — сессией сотрудника. Здесь идентификация
 *   другая: bearer-токен устройства (`AuthenticateChecker`). Смешать два
 *   способа в одном контроллере означало бы, что «кто вызывает» перестаёт быть
 *   читаемым из маршрута, а именно маршрут — единственное место, где это видно
 *   до запуска приложения.
 *
 * ГДЕ ПРИНЯТО РЕШЕНИЕ
 *   В домене: `CheckinEvaluator` решает, `TicketScanService` пишет. Здесь только
 *   транспорт: разбор QR, разрешение идентификаторов, перевод исхода в словарь
 *   контракта (`CheckinVerdict`) и HTTP-статус. Ни одного правила входа в
 *   контроллере нет и добавляться не должно.
 */
final class CheckerController extends Controller
{
    public function __construct(
        private readonly TicketScanService $scans,
        private readonly SessionLocator $sessions,
        private readonly TicketLocator $tickets,
        private readonly DeviceTokenIssuer $issuer,
    ) {}

    /**
     * Проверить билет, не гася его.
     *
     * Отвечает 200 на ЛЮБОЙ вердикт, включая отказ: оператору на экране нужен
     * текст причины, а не «ошибка запроса». Единственное исключение — 401 от
     * middleware.
     */
    public function validate(CheckinValidateRequest $request): JsonResponse
    {
        $device = $this->device($request);
        $this->issuer->touch($device);

        $verified = $this->scans->verifyQr((string) $request->input('qr_payload'));

        if ($verified === null) {
            return $this->respond(CheckinVerdict::INVALID_SIGNATURE, null, 200);
        }

        $ticket = Ticket::query()->where('public_id', $verified['ticketId'])->first();

        if ($ticket === null) {
            return $this->respond(CheckinVerdict::UNKNOWN_TICKET, null, 200);
        }

        $sessionId = $this->sessions->resolve($request->input('session_id'));
        $decision = $this->scans->canCheckin(
            $ticket,
            $sessionId,
            $device->id,
            (int) $device->organization_id,
        );
        $verdict = CheckinVerdict::fromOutcome(
            (string) ($decision['result'] ?? CheckinVerdict::UNKNOWN_TICKET),
            $this->sameEvent($ticket, $sessionId)
        );

        return $this->respond($verdict, $decision['reason'] ?? null, 200, $ticket);
    }

    /**
     * Погасить билет. Идемпотентно по `client_scan_id`.
     *
     * СТАТУСЫ: 200 — пущен или честно отказано (причина в `result`),
     * 409 — `already_used`/`conflict` (контракт: 409 «Already used/conflict»),
     * 401 — нет/не тот токен устройства, 422 — некорректное тело.
     */
    public function use(CheckinUseRequest $request): JsonResponse
    {
        $device = $this->device($request);
        $this->issuer->touch($device);

        $sessionId = $this->sessions->resolve($request->input('session_id'));

        // Двойная валидация QR здесь намеренно не дублируется: `scan()`
        // проверяет подпись сам (A11.2) и возвращает машинную причину, которую
        // `CheckinVerdict` переводит в `invalid_signature`.
        $result = $this->scans->scan(
            ticketId: 0,
            sessionId: $sessionId,
            deviceId: $device->id,
            qrPayload: (string) $request->input('qr_payload'),
            clientScanId: (string) $request->input('client_scan_id'),
            scannedAt: $request->scannedAt(),
            organizationId: (int) $device->organization_id,
        );

        return $this->fromScanResult($result, $sessionId);
    }

    /**
     * Сверка офлайн-сканов (§33, §44).
     *
     * Один запрос — до 5000 сканов. Каждая строка обрабатывается независимо:
     * один unidentified билет не отменяет остальные 4999, иначе потеря сети в
     * конце мероприятия означала бы потерю всего журнала входа.
     *
     * СЧЁТЧИКИ ОТВЕТА (`CheckinSyncResponse`):
     *   accepted   — Outcome::ADMITTED, человек пущен;
     *   duplicates — этот `client_scan_id` уже записан (повтор загрузки);
     *   conflicts  — конфликт: вход был совершён, а билет с тех пор перестал быть
     *                действителен (§44 — билет отзывается);
     *   rejected   — остальное: отменён, возвращён, истёк, другой сеанс.
     */
    public function sync(CheckinSyncRequest $request): JsonResponse
    {
        $device = $this->device($request);
        $this->issuer->touch($device);

        $this->assertSameDevice($device, $request->claimedDeviceId());

        $sessionId = $this->sessions->resolve($request->input('session_id'));

        $accepted = 0;
        $duplicates = 0;
        $conflicts = 0;
        $rejected = 0;
        $results = [];

        foreach ($request->scans() as $scan) {
            /** @var array{client_scan_id: string, ticket_id: mixed, scanned_at: string, result: string} $scan */
            $clientScanId = (string) $scan['client_scan_id'];
            $scannedAt = $this->dateTimeOrNull($scan['scanned_at']);

            try {
                $publicId = $this->tickets->publicIdOf($scan['ticket_id']);
            } catch (ValidationError $e) {
                // Неизвестный билет — это `rejected`, а не пачка всего запроса.
                $rejected++;
                $results[] = [
                    'client_scan_id' => $clientScanId,
                    'result' => CheckinVerdict::UNKNOWN_TICKET,
                    'message' => $e->getMessage(),
                ];

                continue;
            }

            $deviceAdmitted = $this->deviceAdmitted($scan['result'] ?? '');

            $outcome = $this->scans->scanFromBundle(
                ticketPublicId: $publicId,
                // Контракт хеша бандла в sync не предусматривает; проверка
                // опускается на «у устройства есть живой бандл на сеанс».
                bundleHash: null,
                deviceId: $device->id,
                clientScanId: $clientScanId,
                deviceAdmitted: $deviceAdmitted,
                scannedAt: $scannedAt,
                organizationId: (int) $device->organization_id,
            );

            $verdict = CheckinVerdict::fromOutcome((string) ($outcome['result'] ?? ''));

            if (($outcome['replay'] ?? false) === true) {
                $duplicates++;
            } elseif ($outcome['success'] === true) {
                $accepted++;
            } elseif ($verdict === CheckinVerdict::CONFLICT) {
                $conflicts++;
            } else {
                $rejected++;
            }

            $results[] = [
                'client_scan_id' => $clientScanId,
                'result' => $verdict,
                'message' => is_string($outcome['message'] ?? null) ? $outcome['message'] : null,
            ];
        }

        return response()->json([
            'data' => [
                'accepted' => $accepted,
                'duplicates' => $duplicates,
                'conflicts' => $conflicts,
                'rejected' => $rejected,
                'results' => $results,
            ],
        ]);
    }

    /**
     * Скан, который устройство уже загружало, отвечается записанным исходом, а
     * не новым решением, — иначе повтор нажатия выглядел бы как второй вход.
     *
     * ВЕТКА БЕЗ `result` — это отказ ДО оценки: `scan()` возвращает машинный
     * код в `reason` (`QR_SIGNATURE_INVALID`, `TICKET_NOT_FOUND`), когда
     * подпись не сошлась или билет не найден. Сводить такие отказы к
     * `unknown_ticket` нельзя: поддельный QR и несуществующий билет — разные
     * инструкции для оператора.
     */
    private function fromScanResult(array $result, int $sessionId): JsonResponse
    {
        $ticket = $result['ticket'] ?? null;

        // Идемпотентный повтор: тот же (device_id, client_scan_id) уже записан.
        // Контракт отвечает 409 «Already used/conflict» ЗАПИСАННЫМ исходом — ни
        // в коем случае не новым решением, иначе «нажал дважды» выглядело бы как
        // «вошло два человека» (ТЗ §32). Уникальный индекс
        // `uq_ticket_scans_client` держит уникальность на уровне БД, а здесь мы
        // лишь переводим сохранённый доменный исход в код контракта.
        if (($result['replay'] ?? false) === true) {
            $stored = (string) ($result['result'] ?? '');

            $verdict = $stored === ScanOutcome::CONFLICT_REVOKED
                ? CheckinVerdict::CONFLICT
                : CheckinVerdict::ALREADY_USED;

            return $this->respond(
                $verdict,
                $result['reason'] ?? null,
                CheckinVerdict::httpStatus($verdict),
                $ticket instanceof Ticket ? $ticket : null
            );
        }

        $outcome = (string) ($result['result'] ?? '');
        $code = $outcome !== '' ? $outcome : (string) ($result['reason'] ?? '');


        $verdict = match ($code) {
            'QR_SIGNATURE_INVALID' => CheckinVerdict::INVALID_SIGNATURE,
            'TICKET_NOT_FOUND', 'TICKET_ID_MISMATCH' => CheckinVerdict::UNKNOWN_TICKET,
            default => CheckinVerdict::fromOutcome($code, $this->sameEventFromResult($result, $sessionId)),
        };

        return $this->respond($verdict, $result['reason'] ?? null, CheckinVerdict::httpStatus($verdict), $ticket instanceof Ticket ? $ticket : null);
    }

    /**
     * Билет для другого СЕАНСА того же мероприятия — не то же самое, что билет
     * для другого МЕРОПРИЯТИЯ. Контракт различает `wrong_session` и
     * `wrong_event`; различие берётся из сравнения event_id, а не из домена,
     * который оба случая назван `wrong_session`.
     */
    private function sameEvent(Ticket $ticket, int $sessionId): bool
    {
        if ((int) $ticket->session_id === $sessionId) {
            return true;
        }

        $eventId = Session::query()->whereKey($sessionId)->value('event_id');

        return $eventId !== null && (int) $eventId === (int) $ticket->event_id;
    }

    /**
     * То же сравнение для ответа `use`, где билет приходит из результата
     * сервиса, а не из контроллера.
     */
    private function sameEventFromResult(array $result, int $sessionId): bool
    {
        $ticket = $result['ticket'] ?? null;

        if (! $ticket instanceof Ticket) {
            return true;
        }

        return $this->sameEvent($ticket, $sessionId);
    }

    /**
     * Пустило ли устройство человека по своему офлайн-решению.
     *
     * Fail-closed: только явный `valid` считается допуском. Всё остальное —
     * включая незнакомое значение — отказ, потому что «непонятный вердикт»
     * нельзя отличать от «устройство отказало».
     */
    private function deviceAdmitted(string $claimedResult): bool
    {
        return $claimedResult === CheckinVerdict::VALID;
    }

    private function assertSameDevice(CheckinDevice $device, string|int $claimed): void
    {
        $claimedValue = is_int($claimed) ? $claimed : trim($claimed);

        if ($claimedValue === (string) $device->id || $claimedValue === (string) $device->public_id) {
            return;
        }

        throw new AuthError(
            'The device_id in the body does not match the authenticated device.',
            'DEVICE_MISMATCH',
            403
        );
    }

    private function device(mixed $request): CheckinDevice
    {
        return AuthenticateChecker::device($request);
    }

    private function dateTimeOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function respond(string $verdict, ?string $reason, int $status, ?Ticket $ticket = null): JsonResponse
    {
        return response()->json([
            'data' => [
                'result' => $verdict,
                'ticket' => $ticket,
                'message' => CheckinVerdict::messageFor($verdict, $reason),
            ],
        ], $status);
    }
}
