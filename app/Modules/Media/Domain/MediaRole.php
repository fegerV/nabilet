<?php

declare(strict_types=1);

namespace Nabilet\Modules\Media\Domain;

/**
 * Роль файла в связи `media_links`.
 *
 * ЗАЧЕМ КОНСТАНТЫ, ЕСЛИ КОЛОНКА СВОБОДНАЯ
 *
 * `media_links.role` — VARCHAR(64) с DEFAULT 'gallery', и контракт
 * (`openapi.yaml`, `MediaAssetCreate.role`) объявляет его как произвольную
 * строку. Поэтому валидация НЕ ограничивает значение списком ниже: сузить
 * контракт значило бы отвечать 422 на роль, которую спека разрешает.
 *
 * Константы нужны для другого — чтобы код приложения не писал `'gallery'`
 * строкой в пяти местах. Именно так и расходятся значения: одна опечатка в
 * одном модуле, и галерея мероприятия перестаёт находить свои файлы, потому
 * что ищет `'gallery '` вместо `'gallery'`. Список ниже — то, что использует
 * САМ NABILET; клиент вправе передать своё.
 *
 * Роль — часть уникального ключа `uq_media_links`
 * (`entity_type, entity_id, media_asset_id, role`), поэтому один и тот же файл
 * может одновременно быть и афишей, и элементом галереи одного мероприятия:
 * это две разные строки, а не конфликт.
 */
final class MediaRole
{
    /** Элемент галереи — значение по умолчанию для колонки. */
    public const GALLERY = 'gallery';

    /** Главная афиша мероприятия или площадки. */
    public const POSTER = 'poster';

    /** Обложка (широкое изображение для карточки). */
    public const COVER = 'cover';

    /** Логотип организации. */
    public const LOGO = 'logo';

    /** Роль по умолчанию — совпадает с DEFAULT колонки в схеме. */
    public const DEFAULT = self::GALLERY;

    /**
     * Предел длины — совпадает с `media_links.role VARCHAR(64)`.
     *
     * Держим рядом с константами: если колонку когда-нибудь расширят, менять
     * придётся оба места, и лучше, чтобы они лежали в одном файле.
     */
    public const MAX_LENGTH = 64;

    /** @return list<string> */
    public static function all(): array
    {
        return [self::GALLERY, self::POSTER, self::COVER, self::LOGO];
    }
}
