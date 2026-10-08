<?php

declare(strict_types=1);

namespace Nabilet\Modules\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Nabilet\Core\Support\AssetUrl;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Файл в хранилище — `media_assets`.
 *
 * ЭТО ЕДИНСТВЕННЫЙ КАНОНИЧЕСКИЙ МАППИНГ ТАБЛИЦЫ
 *
 * До этого класса в проекте лежали ДВЕ другие копии, и обе были неверны:
 *
 *  * `App\Models\MediaAsset` — колонки угаданы правильно, но модель мертва
 *    (на неё не ссылается никто вне `app/Models/`), а парный
 *    `App\Models\MediaLink` в `boot()` пишет `public_id` в таблицу, где такой
 *    колонки НЕТ, — то есть любая вставка через него падала бы;
 *  * `Nabilet\Modules\Content\Models\MediaAsset` — колонки ВЫДУМАНЫ
 *    (`name`, `alt_text` без `filename`/`path`), а парный `MediaLink` маппит
 *    `subject_type`/`subject_id`/`caption`, которых в схеме тоже нет. При этом
 *    именно на него ссылались рабочие связи `Organization::mediaAssets()` и
 *    `User::uploadedMediaAssets()`.
 *
 * Две модели с одним именем и разными схемами — это и есть причина, по которой
 * модуль считался «пустым»: файлы выглядели написанными. Здесь колонки сверены
 * с `database/migrations/2026_09_20_000200_002_content.php` построчно.
 *
 * ПОЧЕМУ SOFT DELETE
 *
 * Колонка `deleted_at` объявлена в схеме, и удаление файла — операция, которую
 * администратор делает кнопкой, часто по ошибке. Мягкое удаление оставляет
 * возможность отката, а сам файл на диске НЕ удаляется: восстановление записи
 * без файла бессмысленно. Связи (`media_links`) при удалении снимаются явно —
 * см. `MediaService::delete()`, там же объяснено, почему одного каскада FK мало.
 */
class MediaAsset extends Model
{
    use SoftDeletes;

    protected $table = 'media_assets';

    protected $fillable = [
        'public_id',
        'organization_id',
        'disk',
        'path',
        'filename',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'checksum',
        'title',
        'alt_text',
        'variants_json',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'variants_json' => 'array',
    ];

    /**
     * `public_id` — ULID (base32), как и у остальных публичных идентификаторов
     * проекта. `uq_media_assets_public_id` требует уникальности, поэтому
     * генерируем только при создании и никогда не переписываем.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = (string) Str::ulid()->toBase32();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Связи с объектами. Каскад объявлен на уровне БД
     * (`fk_media_links_asset ... ON DELETE CASCADE`), поэтому жёсткое удаление
     * файла само снимает связи. При мягком удалении каскад не срабатывает —
     * об этом см. `MediaService::delete()`.
     */
    public function links(): HasMany
    {
        return $this->hasMany(MediaLink::class, 'media_asset_id');
    }

    /**
     * Публичный URL файла.
     *
     * Через `AssetUrl`, а не склейкой строки: у проекта два разных корня
     * (`/storage/...` для загруженного и `/` для статики), и `AssetUrl` —
     * единственное место, где это правило записано. Здесь всегда `storage()`:
     * `media_assets` описывает загруженные файлы.
     *
     * Если `path` уже абсолютный (внешний URL или CDN), `AssetUrl` вернёт его
     * как есть — поэтому метод безопасен и для записей, зарегистрированных по
     * ссылке, а не загруженных через приложение.
     */
    public function url(): string
    {
        return AssetUrl::storage((string) $this->path);
    }

    /**
     * Уменьшенная копия, если она есть в `variants_json`, иначе — оригинал.
     *
     * ВАРИАНТЫ ПОКА НЕ ГЕНЕРИРУЮТСЯ (см. `MediaService`): колонка принимает
     * произвольный JSON и заполняется вызывающим кодом. Метод существует, чтобы
     * потребитель (галерея) не был обязан знать об этом: сегодня он всегда
     * получает оригинал, а когда появится генерация — начнёт получать превью
     * без правок на стороне вызова.
     */
    public function variantUrl(string $name): string
    {
        $variants = $this->variants_json;

        if (is_array($variants) && isset($variants[$name]) && is_string($variants[$name]) && $variants[$name] !== '') {
            return AssetUrl::storage($variants[$name]);
        }

        return $this->url();
    }
}
