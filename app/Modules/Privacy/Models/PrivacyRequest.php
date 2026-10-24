<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Запрос субъекта персональных данных (доступ/удаление/экспорт).
 *
 * Единственная реализация: до этого рядом жил дубль в
 * `Modules/Notifications/Models` с колонками `reason`, которых в схеме нет, —
 * обращение к нему падало бы на неизвестной колонке. Колонки здесь повторяют
 * `2026_09_20_000600_006_notifications_privacy`.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property string $type 'access' | 'deletion' | 'export'
 * @property string $status 'pending' | 'completed' | 'rejected'
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
        'payload_json',
        'created_at',
        'completed_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

}
