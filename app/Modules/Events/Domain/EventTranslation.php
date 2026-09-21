<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * One row of `event_translations`.
 *
 * Every content column is NULLABLE — `title`, `short_description`, `description`,
 * `seo_title`, `seo_description` — so a row with nothing in it is legal. That
 * matters more here than it looks:
 *
 *   `uq_event_translations (event_id, locale)` means one row per event per locale.
 *   An empty row therefore does not sit there harmlessly — it OCCUPIES the locale.
 *   Verified on MySQL 8.4: after inserting `('event 1', 'en')` with every content
 *   column NULL, the real English translation was rejected with
 *   `ERROR 1062 Duplicate entry '1-en'`. The placeholder has to be deleted before
 *   the locale can ever be filled in, and nothing reports it until somebody tries.
 *
 * It is the same shape as the zero-quantity cart line wedging `uq_cart_inventory`,
 * except `ck_cart_items_quantity` prevents that one and nothing prevents this.
 *
 * WHY AN EMPTY ROW IS REFUSED RATHER THAN IGNORED
 *   A fallback that reads "a translation row exists" would render NULL titles; a
 *   fallback that reads "the row has a title" would silently ignore the row and
 *   leave a lock in the table. Both are wrong, so the write is refused and the
 *   reason says what the lock costs.
 */
final class EventTranslation
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $locale,
        public readonly ?string $title = null,
        public readonly ?string $shortDescription = null,
        public readonly ?string $description = null,
        public readonly ?string $seoTitle = null,
        public readonly ?string $seoDescription = null,
    ) {
        if (trim($locale) === '') {
            throw new DomainRuleViolation(
                sprintf('A translation for event %d must name a locale.', $eventId),
                'INVALID_LOCALE'
            );
        }
    }

    /** Nothing in it at all — the row that locks a locale for nothing. */
    public function isEmpty(): bool
    {
        return $this->title === null
            && $this->shortDescription === null
            && $this->description === null
            && $this->seoTitle === null
            && $this->seoDescription === null;
    }

    /** Enough to be rendered: a title is the minimum an event page can show. */
    public function hasTitle(): bool
    {
        return $this->title !== null && trim($this->title) !== '';
    }

    /**
     * SEO-only rows are legal and useful, but they must not be mistaken for a
     * translation of the page.
     */
    public function isSeoOnly(): bool
    {
        return ! $this->hasTitle() && ($this->seoTitle !== null || $this->seoDescription !== null);
    }

    public function sameLocaleAs(self $other): bool
    {
        return $this->eventId === $other->eventId
            && strtolower(trim($this->locale)) === strtolower(trim($other->locale));
    }
}
