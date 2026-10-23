<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Domain;

/**
 * Состояния редакционной страницы (стратегия SEO, B-2).
 *
 * ОДИН СЛОВАРЬ НА ВСЕХ, А НЕ ЛИТЕРАЛЫ. `status='published'` был написан в двух
 * местах — `PageService::forSlug()` и `CatalogService::pages()` — и оба докблока
 * предупреждали об одном и том же: «расхождение между картой и страницей — это
 * 404 из sitemap'а». Правило, написанное дважды, именно поэтому и расходится:
 * правка одной копии не заставляет вспомнить о второй, а литерал в третьем
 * месте (новая посадочная, очередной сборщик карты) не заметил бы никто.
 *
 * СОВМЕСТИМ С `EventStatus`, НО НЕ ОН. У событий словарь богаче (scheduled,
 * completed, cancelled) — там жизненный цикл с датами и отменами. У страницы
 * документа три состояния, и `ck_pages_status` в схеме их и допускает. Заводить
 * свой класс дешевле, чем тащить шесть неиспользуемых значений в CMS.
 *
 * `archived` пока никем не выставляется: он в словаре, потому что схема его
 * допускает, а «скрыть, сохранив» понадобится раньше, чем появится редактор.
 */
final class PageStatus
{
    /** Черновик: виден автору, недоступен публично. */
    public const DRAFT = 'draft';

    /**
     * Опубликована. Единственное состояние, которое показывается и индексируется.
     *
     * ВДВОЁМ С ДАТОЙ. Сам статус недостаточен: `published_at` NULL или из
     * будущего означает «ещё не вышла». Поэтому оба читателя (`PageService` и
     * `CatalogService`) проверяют пару «статус + дата», а не статус один.
     */
    public const PUBLISHED = 'published';

    /** Снята с показа, но сохранена: перепубликация без повторного ввода. */
    public const ARCHIVED = 'archived';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DRAFT, self::PUBLISHED, self::ARCHIVED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}
