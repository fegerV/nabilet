<?php

declare(strict_types=1);

namespace Nabilet\Modules\Media\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Привязка файла к объекту — `media_links`.
 *
 * ТАБЛИЦА БЕЗ `updated_at`
 *
 * Схема объявляет только `created_at DATETIME(6) NOT NULL`. Поэтому
 * `UPDATED_AT = null`: Laravel проставит `created_at` сам, а колонку, которой
 * нет, трогать не будет. `public $timestamps = false` здесь был бы неверен —
 * он отключил бы и `created_at`, и вставка упала бы на NOT NULL.
 *
 * РАНЬШЕ ЭТА ТАБЛИЦА МАППИЛАСЬ НЕВЕРНО
 *
 * `Nabilet\Modules\Content\Models\MediaLink` объявлял колонки
 * `subject_type`/`subject_id`/`caption`, которых в схеме нет, и связи с
 * объектами через него не работали бы вообще. Здесь колонки совпадают с
 * миграцией: `entity_type`, `entity_id`, `role`, `position`.
 *
 * УНИКАЛЬНОСТЬ — ЧАСТЬ КОНТРАКТА
 *
 * `uq_media_links (entity_type, entity_id, media_asset_id, role)` запрещает
 * привязать один файл дважды в одной роли. Поэтому привязка делается через
 * `updateOrCreate` (см. `MediaService::attach()`): повторный POST не должен
 * давать 500 от нарушения уникального ключа, он должен быть идемпотентным.
 */
class MediaLink extends Model
{
    protected $table = 'media_links';

    /**
     * `media_links` не имеет `updated_at` — только `created_at`.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'media_asset_id',
        'entity_type',
        'entity_id',
        'role',
        'position',
    ];

    protected $casts = [
        'media_asset_id' => 'integer',
        'entity_id' => 'integer',
        'position' => 'integer',
    ];

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /**
     * Связи конкретного объекта: `MediaLink::forEntity('event', 42)`.
     *
     * Скоуп, а не полиморфная связь, — причина в `MediaEntityType`. Порядок
     * задан здесь, а не у вызывающего: `position` — часть смысла галереи, и
     * «забыл `orderBy`» не должно выглядеть как «порядок сбросился».
     */
    public function scopeForEntity(Builder $query, string $entityType, int $entityId, ?string $role = null): Builder
    {
        $query->where('entity_type', $entityType)->where('entity_id', $entityId);

        if ($role !== null) {
            $query->where('role', $role);
        }

        return $query->orderBy('position')->orderBy('id');
    }
}
