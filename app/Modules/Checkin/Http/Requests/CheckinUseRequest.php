<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/checkin/use` — тело по `openapi.yaml` `CheckinUseRequest`:
 * `qr_payload` + `session_id` + `client_scan_id` обязательны, `scanned_at` и
 * `mode` — нет.
 *
 * `client_scan_id` ОБЯЗАТЕЛЕН И ЭТО UUID, А НЕ «СТРОКА»
 *   Контракт говорит `format: uuid`, и это не формальность: уникальный индекс
 *   `uq_ticket_scans_client (device_id, client_scan_id)` схлопывает повторы
 *   устройства, но только если значение действительно уникально на устройство.
 *   Строка вида `scan-1` на каждом из двадцати устройств мероприятия сделала бы
 *   дедупликацию враждебной: первое устройство заблокировало бы ключ остальным.
 *   Поэтому здесь `uuid`, а не `string`.
 *
 *   Устройство может работать и офлайн: тогда `scanned_at` несёт время у двери,
 *   и `TicketScanService` пишет именно его. Момент сервера подставляется только
 *   когда устройство его не прислало.
 */
final class CheckinUseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'qr_payload' => ['required', 'string', 'max:1024'],
            'session_id' => ['required'],
            'client_scan_id' => ['required', 'uuid'],
            // Момент сканирования с устройства. null допустим: онлайн-скан
            // фиксируется временем сервера, и null здесь — не ошибка клиента.
            'scanned_at' => ['nullable', 'date'],
            'mode' => ['nullable', 'string', 'in:online,offline'],
        ];
    }

    /**
     * Момент сканирования или null — тогда запись получает время сервера
     * (`TicketScanService::scan()`).
     */
    public function scannedAt(): ?\DateTimeImmutable
    {
        $raw = $this->input('scanned_at');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
