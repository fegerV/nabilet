<?php

declare(strict_types=1);

namespace Nabilet\Modules\Media\Domain;

/**
 * К какому объекту привязан файл — `media_links.entity_type`.
 *
 * ПОЧЕМУ СТРОКА, А НЕ ПОЛИМОРФНАЯ СВЯЗЬ ELOQUENT
 *
 * Схема объявляет `entity_type VARCHAR(100)` + `entity_id BIGINT UNSIGNED` и
 * индекс `idx_media_links_entity (entity_type, entity_id)`. Это классический
 * полиморфный ключ, и Laravel умеет то же самое через `morphTo`, записывая в
 * колонку ПОЛНОЕ ИМЯ КЛАССА (`Nabilet\Modules\Events\Models\Event`).
 *
 * Так делать нельзя по двум причинам:
 *
 *  1. Внешнего ключа на `entity_id` нет и быть не может, поэтому целостность
 *     держится только кодом. Имя класса в колонке связывает строку в БД с
 *     пространством имён PHP: переименование или перенос модуля превращает
 *     все существующие связи в мусор, который ничем не отловить.
 *  2. Значение видно снаружи — в отчётах, выгрузках и SQL-запросах. Читать
 *     `Nabilet\Modules\Events\Models\Event` в колонке хуже, чем `event`.
 *
 * Поэтому в колонке лежит короткое стабильное имя из списка ниже, и связь
 * собирается вручную (`MediaLink::forEntity()`).
 *
 * Список НЕ закрыт: колонка на 100 символов, а модули добавляются. Валидация
 * проверяет длину и непустоту, а не принадлежность списку, — иначе каждый
 * новый модуль требовал бы правки этого файла, чтобы привязать первый файл.
 */
final class MediaEntityType
{
    /** Мероприятие (`events.id`). */
    public const EVENT = 'event';

    /** Площадка (`venues.id`). */
    public const VENUE = 'venue';

    /** Зал (`halls.id`). */
    public const HALL = 'hall';

    /** Организация-арендатор (`organizations.id`). */
    public const ORGANIZATION = 'organization';

    /** Статическая страница (`pages.id`). */
    public const PAGE = 'page';

    /**
     * Предел длины — совпадает с `media_links.entity_type VARCHAR(100)`.
     */
    public const MAX_LENGTH = 100;

    /** @return list<string> */
    public static function all(): array
    {
        return [self::EVENT, self::VENUE, self::HALL, self::ORGANIZATION, self::PAGE];
    }
}
