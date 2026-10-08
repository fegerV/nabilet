<?php

declare(strict_types=1);

namespace Nabilet\Modules\Media\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Media\Domain\MediaEntityType;
use Nabilet\Modules\Media\Domain\MediaRole;
use Nabilet\Modules\Media\Models\MediaAsset;
use Nabilet\Modules\Media\Models\MediaLink;

/**
 * Файлы: загрузка, метаданные, привязка к объектам.
 *
 * ГРАНИЦА МОДУЛЯ
 *
 * Модуль знает про файлы и про то, что файл можно привязать к объекту с
 * идентификатором (`entity_type` + `entity_id`). Он НЕ знает, что такое
 * мероприятие: ни таблицы `events`, ни её модели здесь нет. Поэтому галерея
 * мероприятия — это `forEntity('event', $id)` из контроллера Events, а не
 * отдельная сущность в Media. Так модуль остаётся переиспользуемым, а
 * `media_links` — одной таблицей вместо таблицы на каждый вид объекта.
 *
 * ДВЕ ДОРОГИ СОЗДАНИЯ ЗАПИСИ
 *
 *  1. `store()` — файл пришёл multipart-ом, приложение само кладёт его на диск
 *     и само считает размер, контрольную сумму и размеры изображения.
 *  2. `register()` — файл уже где-то лежит (загружен напрямую в S3 по
 *     presigned URL, или это внешний URL), и запись только описывает его.
 *     Именно эту дорогу описывает контракт: `MediaAssetCreate` требует
 *     `filename`, `mime_type`, `path` и НЕ содержит поля с файлом.
 *
 * Обе возвращают одну и ту же модель, поэтому потребителю не важно, какой
 * дорогой запись появилась.
 *
 * ВАРИАНТЫ ИЗОБРАЖЕНИЙ НЕ ГЕНЕРИРУЮТСЯ
 *
 * `media_assets.variants_json` существует, GD в сборке есть, но генерации
 * превью здесь СОЗНАТЕЛЬНО нет. Причина не в лени: генерация — это отдельный
 * конвейер (ориентация по EXIF, альфа-канал, лимиты памяти на больших файлах,
 * инвалидация при замене), и незаверенный конвейер хуже его отсутствия — он
 * заполняет базу путями к файлам, которых нет. Колонка принимает произвольный
 * JSON и заполняется вызывающим кодом; `MediaAsset::variantUrl()` уже умеет
 * читать её, когда данные появятся.
 */
class MediaService
{
    /**
     * Каталог внутри диска. Один на все файлы модуля: разделять по типам
     * (`posters/`, `gallery/`) значило бы решать за потребителя, а файл может
     * быть привязан к нескольким объектам сразу и менять роль.
     */
    public const DIRECTORY = 'media';

    /**
     * Диск по умолчанию.
     *
     * НЕ `filesystems.default`: он равен `local`, а `local` в этом проекте
     * указывает на `storage/app/private` — каталог, который веб-сервер не
     * отдаёт. Файл, записанный туда, недоступен по URL вообще, то есть афиша и
     * галерея молча ломаются: запись есть, картинки нет. Для публичных файлов
     * предназначен диск `public` (`storage/app/public` → `/storage/...`).
     *
     * Значение можно переопределить (`nabilet.media.disk`), чтобы установка с
     * несколькими узлами перешла на `s3` без правки кода.
     */
    public const DEFAULT_DISK = 'public';

    public function __construct(
        private readonly ?string $disk = null,
    ) {}

    public function disk(): string
    {
        if ($this->disk !== null && $this->disk !== '') {
            return $this->disk;
        }

        return (string) (config('nabilet.media.disk') ?: self::DEFAULT_DISK);
    }

    /**
     * Сохранить загруженный файл и описать его записью в `media_assets`.
     *
     * @param  array<string, mixed>  $attributes  title, alt_text, uploaded_by, organization_id, variants_json
     */
    public function store(UploadedFile $file, array $attributes = []): MediaAsset
    {
        $disk = $this->disk();

        // Имя выводим из РЕАЛЬНОГО мим-типа, а не из расширения, присланного
        // клиентом: `hashName()` берёт расширение у `guessExtension()`, который
        // читает содержимое через finfo. Клиентское имя сохраняется отдельно —
        // в колонке `filename`, где оно и должно быть, и не влияет на путь.
        $extension = pathinfo((string) $file->hashName(), PATHINFO_EXTENSION) ?: 'bin';
        $path = self::DIRECTORY . '/' . Str::ulid()->toBase32() . '.' . $extension;

        $stored = $file->storeAs(self::DIRECTORY, basename($path), [
            'disk' => $disk,
            'visibility' => 'public',
        ]);

        if ($stored === false) {
            throw new ValidationError(
                ['file' => ['Не удалось записать файл на диск «' . $disk . '».']],
                'Ошибка загрузки файла.'
            );
        }

        // Размеры и контрольная сумма считаются ДО потери ссылки на временный
        // файл. Для не-изображений `getimagesize()` возвращает false — это
        // нормальный случай (PDF, видео), а не ошибка.
        [$width, $height] = $this->imageSize($file);

        return MediaAsset::query()->create([
            'organization_id' => $attributes['organization_id'] ?? null,
            'disk' => $disk,
            'path' => (string) $stored,
            'filename' => (string) ($file->getClientOriginalName() ?: basename((string) $stored)),
            'mime_type' => (string) ($file->getClientMimeType() ?: 'application/octet-stream'),
            'size_bytes' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
            'checksum' => $this->checksum($file),
            'title' => $attributes['title'] ?? null,
            'alt_text' => $attributes['alt_text'] ?? null,
            'variants_json' => $attributes['variants_json'] ?? null,
            'uploaded_by' => $attributes['uploaded_by'] ?? null,
        ]);
    }

    /**
     * Описать файл, который уже лежит в хранилище.
     *
     * Приложение НЕ проверяет, существует ли файл по `path`, и не скачивает
     * его: `path` может указывать на внешний URL или на объект в S3, к
     * которому у приложения нет доступа. Проверка существования превратила бы
     * регистрацию в сетевой вызов на каждый запрос и всё равно не дала бы
     * гарантии. Ответственность за валидность пути несёт вызывающий код.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): MediaAsset
    {
        return MediaAsset::query()->create([
            'organization_id' => $data['organization_id'] ?? null,
            'disk' => $data['disk'] ?? $this->disk(),
            'path' => (string) $data['path'],
            'filename' => (string) $data['filename'],
            'mime_type' => (string) $data['mime_type'],
            'size_bytes' => (int) ($data['size_bytes'] ?? 0),
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'checksum' => $data['checksum'] ?? null,
            'title' => $data['title'] ?? null,
            'alt_text' => $data['alt_text'] ?? null,
            'variants_json' => $data['variants_json'] ?? null,
            'uploaded_by' => $data['uploaded_by'] ?? null,
        ]);
    }

    /**
     * Обновить метаданные.
     *
     * `path`, `disk` и `size_bytes` не обновляются намеренно: это описание
     * физического файла, а не свойство карточки. Замена файла — это новый
     * `media_assets`, а не правка существующей записи: иначе все объекты,
     * привязанные к ней, в один момент начнут показывать другое изображение.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(MediaAsset $asset, array $data): MediaAsset
    {
        $asset->fill(array_intersect_key($data, array_flip([
            'title', 'alt_text', 'variants_json',
        ])));

        $asset->save();

        return $asset;
    }

    /**
     * Удалить файл.
     *
     * СВЯЗИ СНИМАЮТСЯ ЯВНО, И ЭТО НЕ ДУБЛИРОВАНИЕ КАСКАДА
     *
     * В схеме объявлено `fk_media_links_asset ... ON DELETE CASCADE`, и на
     * жёстком удалении он действительно сработал бы. Но удаление здесь МЯГКОЕ
     * (`SoftDeletes`): строка `media_assets` остаётся, `DELETE` на уровне SQL
     * не выполняется, и каскад не срабатывает НИКОГДА. Без явного снятия связей
     * `media_links` накапливал бы строки, указывающие на удалённый файл, а
     * галерея мероприятия показывала бы пустые карточки.
     *
     * Сам файл на диске НЕ удаляется: мягкое удаление обратимо, а запись без
     * файла восстановить нечем. Физическая уборка — отдельная задача
     * (сборщик мусора по `deleted_at`), и она должна быть именно отдельной:
     * удалять файл в том же запросе, который отвечает администратору, значит
     * делать ошибку необратимой.
     */
    public function delete(MediaAsset $asset): void
    {
        DB::transaction(function () use ($asset): void {
            $asset->links()->delete();
            $asset->delete();
        });
    }

    /**
     * Привязать файл к объекту.
     *
     * `updateOrCreate` по уникальному ключу `uq_media_links`, а не `create`:
     * повторный POST с теми же параметрами должен быть идемпотентным, а не
     * отдавать 500 от нарушения уникальности. Побочный эффект приятный —
     * повторная привязка с новым `position` просто переставляет файл.
     *
     * `position` по умолчанию — в конец: `max + 1` среди связей того же объекта
     * и той же роли. Без этого все связи получали бы 0, и порядок галереи
     * определялся бы порядком вставки — то есть был бы случаен при любом
     * изменении.
     */
    public function attach(
        MediaAsset $asset,
        string $entityType,
        int $entityId,
        string $role = MediaRole::DEFAULT,
        ?int $position = null,
    ): MediaLink {
        return MediaLink::query()->updateOrCreate(
            [
                'media_asset_id' => (int) $asset->id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'role' => $role,
            ],
            [
                'position' => $position ?? $this->nextPosition($entityType, $entityId, $role),
            ]
        );
    }

    /**
     * Снять привязку. Возвращает число снятых связей.
     *
     * `role = null` снимает все роли файла у объекта — это то, что нужно
     * кнопке «убрать из галереи», когда файл мог быть привязан и как афиша.
     */
    public function detach(MediaAsset $asset, string $entityType, int $entityId, ?string $role = null): int
    {
        $query = MediaLink::query()
            ->where('media_asset_id', (int) $asset->id)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId);

        if ($role !== null) {
            $query->where('role', $role);
        }

        return $query->delete();
    }

    /**
     * Связи объекта с подгруженными файлами, в порядке `position`.
     *
     * Удалённые файлы не попадают в результат: глобальный скоуп `SoftDeletes`
     * на `MediaAsset` применяется внутри `with('mediaAsset')`, поэтому у такой
     * связи `mediaAsset` будет `null`. Фильтруем явно, а не полагаемся на это:
     * иначе потребитель получает связь без файла и падает на `->url()`.
     *
     * @return Collection<int, MediaLink>
     */
    public function forEntity(string $entityType, int $entityId, ?string $role = null): Collection
    {
        return MediaLink::query()
            ->forEntity($entityType, $entityId, $role)
            ->with('mediaAsset')
            ->get()
            ->filter(static fn (MediaLink $link): bool => $link->mediaAsset !== null)
            ->values();
    }

    /**
     * Найти файл по числовому id, не выходя за пределы организации.
     *
     * `organization_id` в `media_assets` NULLABLE, поэтому одного условия
     * `where('organization_id', $orgId)` мало: записи без организации (их
     * создаёт, например, установщик) стали бы невидимы, а `where(..., null)`
     * в SQL — это `IS NULL`, то есть другое условие. Поэтому «свои» и
     * «ничейные» считаются доступными, а чужой id даёт 404, а не 403:
     * 403 подтвердил бы существование файла другой организации.
     */
    public function findForOrganization(int $id, int $organizationId): MediaAsset
    {
        $asset = MediaAsset::query()
            ->where('id', $id)
            ->where(function ($query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)
                    ->orWhereNull('organization_id');
            })
            ->first();

        if ($asset === null) {
            throw new NotFoundError('MediaAsset', (string) $id);
        }

        return $asset;
    }

    /** Диск как объект — для удаления/чтения, когда понадобится уборка. */
    public function filesystem(): Filesystem
    {
        return Storage::disk($this->disk());
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function imageSize(UploadedFile $file): array
    {
        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_file($realPath)) {
            return [null, null];
        }

        // `@` обязателен: для не-изображений getimagesize() пишет в лог
        // предупреждение «Read error», а это штатный случай (PDF, видео), а не
        // сбой. Подавляем предупреждение, но НЕ ошибку: ошибка остаётся
        // исключением и обрабатывается вызывающим кодом.
        $size = @getimagesize($realPath);

        if ($size === false) {
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    private function checksum(UploadedFile $file): ?string
    {
        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_file($realPath)) {
            return null;
        }

        $hash = hash_file('sha256', $realPath);

        // `media_assets.checksum` — CHAR(64), то есть hex-представление sha256.
        return $hash === false ? null : $hash;
    }

    private function nextPosition(string $entityType, int $entityId, string $role): int
    {
        $max = MediaLink::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('role', $role)
            ->max('position');

        return $max === null ? 0 : ((int) $max + 1);
    }
}
