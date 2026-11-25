<?php

declare(strict_types=1);

namespace Nabilet\Modules\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

/**
 * Виртуальная папка медиатеки (см. миграцию 2026_11_20_050000).
 *
 * Папка не меняет путь файла на диске — она только группирует записи, поэтому
 * перемещение не ломает ссылки на афиши и галереи.
 */
final class MediaFolder extends Model
{
    use HasPublicId;

    protected $table = 'media_folders';

    protected $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        // Без Larastan PHPStan теряет тип после orderBy() на relation и думает,
        // что здесь Query\Builder. Возвращаем саму связь после мутации.
        $relation = $this->hasMany(self::class, 'parent_id');
        $relation->orderBy('name');

        return $relation;
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'folder_id');
    }
}
