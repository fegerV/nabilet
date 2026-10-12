<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/checkin/sync` — тело по `openapi.yaml` `CheckinSyncRequest`:
 * `device_id` + `session_id` + `scans[]`, где каждый скан несёт
 * `client_scan_id`, `ticket_id`, `scanned_at` и `result`.
 *
 * ЗАЧЕМ `device_id`, ЕСЛИ УСТРОЙСТВО УЖЕ ИЗВЕСТНО ПО ТОКЕНУ
 *   Контракт требует поле, и это поле используется как ПЕРЕКРЁСТНАЯ ПРОВЕРКА:
 *   запрос, подписанный токеном устройства A и заявляющий `device_id` устройства
 *   B, — отказ (`DEVICE_MISMATCH`). Без такой проверки токен одного устройства
 *   позволил бы писать сканы от имени другого, и аудит «кто принял билет»
 *   стал бы бессмысленным.
 *
 * `result` СВОБОДНАЯ СТРОКА, А НЕ ПЕРЕЧИСЛЕНИЕ
 *   Так в контракте. Устройство присылает свой вердикт словарём `CheckinResult`
 *   (те же десять значений, что и в ответе), и он переводится в булево
 *   «устройство пустило»: `valid` — пустило, всё остальное — нет. Значит,
 *   неизвестное значение трактуется как отказ, то есть fail-closed.
 */
final class CheckinSyncRequest extends FormRequest
{
    /** Контракт: `scans` — не более 5000 элементов. */
    private const MAX_SCANS = 5000;

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
            'device_id' => ['required'],
            'session_id' => ['required'],
            'scans' => ['required', 'array', 'max:' . self::MAX_SCANS],
            'scans.*.client_scan_id' => ['required', 'uuid'],
            'scans.*.ticket_id' => ['required'],
            'scans.*.scanned_at' => ['required', 'date'],
            'scans.*.result' => ['required', 'string', 'max:64'],
            'scans.*.mode' => ['nullable', 'string', 'in:offline'],
        ];
    }

    /**
     * Заявленный идентификатор устройства (число или public_id) — сверяется с
     * тем, что предъявил токен.
     *
     * @return string|int
     */
    public function claimedDeviceId(): string|int
    {
        $value = $this->input('device_id');

        return is_int($value) ? $value : (string) $value;
    }

    /**
     * @return list<array{client_scan_id: string, ticket_id: string, scanned_at: string, result: string}>
     */
    public function scans(): array
    {
        /** @var list<array{client_scan_id: string, ticket_id: string, scanned_at: string, result: string}> $scans */
        $scans = array_values($this->input('scans', []));

        return $scans;
    }
}
