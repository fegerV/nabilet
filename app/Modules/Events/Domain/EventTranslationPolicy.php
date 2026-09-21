<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain;

/**
 * Whether a translation row may be written (ТЗ §73).
 *
 * Reproduced against MySQL 8.4 first: an `event_translations` row with every
 * content column NULL was accepted, and the real translation for the same locale
 * was then rejected with `ERROR 1062` (§3.20).
 */
final class EventTranslationPolicy
{
    public const ALLOWED = 'allowed';
    public const EMPTY_TRANSLATION = 'empty_translation';

    /**
     * May this row be written?
     *
     * An empty row is refused because it is not inert: `uq_event_translations`
     * gives it the locale, and the only way to fill that locale afterwards is to
     * find and delete the placeholder first.
     */
    public function writeDecision(EventTranslation $translation): PublicationDecision
    {
        if ($translation->isEmpty()) {
            return PublicationDecision::denied(
                self::EMPTY_TRANSLATION,
                sprintf(
                    'Translation for event %d / "%s" has no content in any column. Writing it '
                    . 'would occupy the locale in uq_event_translations and the real translation '
                    . 'would then be rejected with ERROR 1062 until the placeholder is deleted.',
                    $translation->eventId,
                    $translation->locale
                )
            );
        }

        return PublicationDecision::allowed();
    }

    /**
     * Replacing a row: the incoming one must be at least as complete as the one it
     * displaces, or an update silently downgrades a finished translation to SEO
     * metadata.
     */
    public function replaceDecision(EventTranslation $current, EventTranslation $next): PublicationDecision
    {
        $allowed = $this->writeDecision($next);
        if (! $allowed->isAllowed()) {
            return $allowed;
        }

        if ($current->hasTitle() && ! $next->hasTitle()) {
            return PublicationDecision::denied(
                self::EMPTY_TRANSLATION,
                sprintf(
                    'Event %d / "%s" currently has a title; the replacement has none, which '
                    . 'would leave the locale rendered as empty.',
                    $current->eventId,
                    $current->locale
                )
            );
        }

        return PublicationDecision::allowed();
    }
}
