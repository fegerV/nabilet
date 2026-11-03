<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Запрос субъекта персональных данных (152-ФЗ, пять видов обращений):
 * доступ / удаление (erasure) / исправление (rectification) / ограничение
 * обработки (restriction) / переносимость (portability).
 *
 * Единственная реализация: до этого рядом жил дубль в
 * `Modules/Notifications/Models` с колонками `reason`, которых в схеме нет, —
 * обращение к нему падало бы на неизвестной колонке. Колонки здесь повторяют
 * `2026_09_20_000600_006_notifications_privacy` плюс `due_at` из
 * `2026_11_03_000100_privacy_guards` (§3.15): срок ответа записывается
 * создателем строки — число не фиксировано схемой и передаётся параметром
 * (`PrivacyRequest::dueAt()`), но записанный дедлайн обязан иметь склеп.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property string $type 'access' | 'erasure' | 'rectification' | 'restriction' | 'portability'
 * @property string $status 'requested' | 'in_progress' | 'completed' | 'rejected'
 * @property \Carbon\Carbon $due_at записанный срок ответа на обращение
 * @property array|null $payload_json
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon|null $completed_at
 */
class PrivacyRequest extends Model
{
    use HasPublicId;

    /** Явно, а не через множественное число Eloquent — см. `Consent`. */
    protected $table = 'privacy_requests';

    public $timestamps = false;

    protected $fillable = [
        'public_id',
        'user_id',
        'type',
        'status',
        'due_at',
        'payload_json',
        'created_at',
        'completed_at',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'payload_json' => 'array',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

}
