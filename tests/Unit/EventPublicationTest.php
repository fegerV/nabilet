<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Events\Domain\Event;
use Nabilet\Modules\Events\Domain\EventPublicationPolicy;
use Nabilet\Modules\Events\Domain\EventTranslation;
use Nabilet\Modules\Events\Domain\EventTranslationPolicy;
use Nabilet\Modules\Events\Domain\PublicationDecision;
use Nabilet\Tests\Support\TestCase;

/**
 * Publishing and cancelling an event, and translating it (ТЗ §10, §13, §73).
 *
 * Reproduced against MySQL 8.4 first, in `tools/repro-event-publication.sql`:
 *
 *   - `status = 'published'` with `published_at = NULL` was accepted, and so was a
 *     publication a year in the future;
 *   - a published event with zero sessions was accepted — no date, no price;
 *   - `status = 'cancelled'` was accepted with no condition at all, because the
 *     row has no column that could consult sales;
 *   - `status = 'banana'` was accepted;
 *   - an `event_translations` row with every content column NULL was accepted, and
 *     the real English translation was then rejected with ERROR 1062.
 */
final class EventPublicationTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-01 12:00:00');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function event(
        string $status = 'draft',
        ?\DateTimeImmutable $publishedAt = null,
        int $sessionCount = 2,
        int $soldUnits = 0,
        ?\DateTimeImmutable $deletedAt = null,
        int|string $id = 1,
    ): Event {
        return new Event(
            id: $id,
            organizationId: 100,
            slug: 'concert',
            status: $status,
            publishedAt: $publishedAt,
            deletedAt: $deletedAt,
            sessionCount: $sessionCount,
            soldUnits: $soldUnits,
        );
    }

    private function policy(): EventPublicationPolicy
    {
        return new EventPublicationPolicy();
    }

    private function translations(): EventTranslationPolicy
    {
        return new EventTranslationPolicy();
    }

    private function translation(string $locale = 'en', ?string $title = 'Concert'): EventTranslation
    {
        return new EventTranslation(eventId: 1, locale: $locale, title: $title);
    }

    // ── the row ──────────────────────────────────────────────────────────────

    public function testAPublishedEventWithAMomentIsVisible(): void
    {
        $event = $this->event(Event::PUBLISHED, $this->now->modify('-1 day'));

        $this->assertTrue($event->isVisibleAt($this->now));
        $this->assertFalse($event->isScheduledAt($this->now));
    }

    public function testAnEventPublishedInTheFutureIsNotVisibleYet(): void
    {
        // Accepted by MySQL. "Published" means the organizer finished, not that
        // the public may look.
        $event = $this->event(Event::PUBLISHED, $this->now->modify('+1 year'));

        $this->assertFalse($event->isVisibleAt($this->now));
        $this->assertTrue($event->isScheduledAt($this->now));
    }

    public function testAnEventPublishedWithoutAMomentIsDetectable(): void
    {
        // The row MySQL accepted: on the site with no answer to "since when?".
        $event = $this->event(Event::PUBLISHED, null);

        $this->assertTrue($event->publishedWithoutAMoment());
        $this->assertFalse($event->isVisibleAt($this->now));
    }

    public function testADeletedEventIsNeverVisibleEvenIfPublished(): void
    {
        $event = $this->event(Event::PUBLISHED, $this->now->modify('-1 day'), 2, 0, $this->now);

        $this->assertFalse($event->isVisibleAt($this->now));
        $this->assertTrue($event->isDeleted());
    }

    public function testAnUnknownStatusIsNotPublished(): void
    {
        // 'banana' was accepted. The status is compared, never validated.
        $this->assertFalse($this->event('banana')->isPublished());
        $this->assertFalse($this->event('banana')->isVisibleAt($this->now));
    }

    public function testInconsistentFactsAreRefusedAtConstruction(): void
    {
        // Units are sold against sessions; a caller claiming otherwise is passing
        // facts that cannot both be true.
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->event(sessionCount: 0, soldUnits: 3),
            'sold units with no sessions must be refused'
        );
    }

    // ── publishing ──────────────────────────────────────────────────────────

    public function testADraftWithSessionsCanBePublished(): void
    {
        $decision = $this->policy()->publishDecision($this->event());

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(PublicationDecision::ALLOWED, $decision->verdict);
        $this->assertTrue($decision->requiresWrite());
    }

    public function testAnEventWithNoSessionsCannotBePublished(): void
    {
        // The one rule here a product owner might relax — and this is the single
        // place to do it.
        $decision = $this->policy()->publishDecision($this->event(sessionCount: 0));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(PublicationDecision::NO_SESSIONS, $decision->verdict);
    }

    public function testADeletedEventCannotBePublished(): void
    {
        $decision = $this->policy()->publishDecision($this->event(deletedAt: $this->now));

        $this->assertSame(PublicationDecision::EVENT_DELETED, $decision->verdict);
    }

    public function testRepublishingAnEventThatAlreadyHasAMomentIsNoChange(): void
    {
        // Saving the form twice must not write an UPDATE that bumps updated_at
        // and nothing else.
        $decision = $this->policy()->publishDecision(
            $this->event(Event::PUBLISHED, $this->now->modify('-1 day'))
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(PublicationDecision::NO_CHANGE, $decision->verdict);
        $this->assertFalse($decision->requiresWrite());
    }

    public function testPublishingRepairsAnEventThatHasNoMoment(): void
    {
        // The row MySQL allowed. Republishing it is work: the missing moment gets
        // written, which repairs the row.
        $decision = $this->policy()->publishDecision($this->event(Event::PUBLISHED, null));

        $this->assertTrue($decision->requiresWrite());
        $this->assertSame(PublicationDecision::ALLOWED, $decision->verdict);
    }

    // ── cancelling ──────────────────────────────────────────────────────────

    public function testAnEventWithNothingSoldCanBeCancelled(): void
    {
        $this->assertTrue($this->policy()->cancelDecision($this->event(Event::PUBLISHED))->isAllowed());
    }

    public function testAnEventWithSoldUnitsCannotBeCancelled(): void
    {
        // Nothing in the schema consults sales: the UPDATE was accepted with no
        // condition at all. So the objection has to live here.
        $decision = $this->policy()->cancelDecision($this->event(Event::PUBLISHED, null, 2, 40));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(PublicationDecision::HAS_SOLD_UNITS, $decision->verdict);
        $this->assertStringContainsString('40', (string) $decision->reason);
    }

    public function testCancellingAnAlreadyDeletedEventIsNoChange(): void
    {
        $decision = $this->policy()->cancelDecision($this->event(deletedAt: $this->now));

        $this->assertSame(PublicationDecision::NO_CHANGE, $decision->verdict);
    }

    // ── auditing ────────────────────────────────────────────────────────────

    public function testTheAuditPassesAnOrdinaryPublishedEvent(): void
    {
        $this->assertTrue($this->policy()->auditDecision(
            $this->event(Event::PUBLISHED, $this->now->modify('-1 day')),
            $this->now
        )->isAllowed());
    }

    public function testTheAuditFindsTheRowTheSchemaAccepted(): void
    {
        $decision = $this->policy()->auditDecision($this->event(Event::PUBLISHED, null), $this->now);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(PublicationDecision::PUBLISHED_WITHOUT_A_MOMENT, $decision->verdict);
    }

    public function testTheAuditReportsAScheduledPublicationRatherThanRefusingIt(): void
    {
        // Scheduled publication is legitimate. What matters is that it is not
        // treated as visible, which isVisibleAt() already does.
        $decision = $this->policy()->auditDecision(
            $this->event(Event::PUBLISHED, $this->now->modify('+1 year')),
            $this->now
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(PublicationDecision::SCHEDULED, $decision->verdict);
    }

    public function testTheAuditFindsAPublishedEventWithNoSessions(): void
    {
        $decision = $this->policy()->auditDecision(
            $this->event(Event::PUBLISHED, $this->now->modify('-1 day'), 0),
            $this->now
        );

        $this->assertSame(PublicationDecision::NO_SESSIONS, $decision->verdict);
    }

    public function testTheMissingMomentOutranksTheMissingSessions(): void
    {
        // Both faults at once: report the moment, because that is the defect the
        // schema allowed; "no sessions" would be a normal-sounding answer.
        $decision = $this->policy()->auditDecision($this->event(Event::PUBLISHED, null, 0), $this->now);

        $this->assertSame(PublicationDecision::PUBLISHED_WITHOUT_A_MOMENT, $decision->verdict);
    }

    // ── translations ────────────────────────────────────────────────────────

    public function testATranslationWithATitleCanBeWritten(): void
    {
        $this->assertTrue($this->translations()->writeDecision($this->translation())->isAllowed());
    }

    public function testAnEmptyTranslationCannotBeWritten(): void
    {
        // Accepted by MySQL, and then the real one was rejected with 1062: the
        // placeholder occupies the locale in uq_event_translations.
        $decision = $this->translations()->writeDecision($this->translation('en', null));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(EventTranslationPolicy::EMPTY_TRANSLATION, $decision->verdict);
        $this->assertStringContainsString('1062', (string) $decision->reason);
    }

    public function testASeoOnlyRowIsLegalAndIsNotATitle(): void
    {
        $row = new EventTranslation(eventId: 1, locale: 'en', seoTitle: 'Concert — tickets');

        $this->assertFalse($row->isEmpty());
        $this->assertFalse($row->hasTitle());
        $this->assertTrue($row->isSeoOnly());
        $this->assertTrue($this->translations()->writeDecision($row)->isAllowed());
    }

    public function testAReplacementMayNotDropTheTitle(): void
    {
        // The replacement must NOT be empty, or this test would be stopped by the
        // empty-row rule and pass for the wrong reason. A row with SEO metadata but
        // no title is exactly the downgrade being guarded against.
        $decision = $this->translations()->replaceDecision(
            $this->translation('en', 'Concert'),
            new EventTranslation(eventId: 1, locale: 'en', seoTitle: 'Concert — tickets')
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(EventTranslationPolicy::EMPTY_TRANSLATION, $decision->verdict);
    }

    public function testARowWithOnlyABodyIsNotEmpty(): void
    {
        // Every content column is nullable, so "empty" has to mean every one of
        // them — dropping one from the check lets a placeholder through.
        $row = new EventTranslation(eventId: 1, locale: 'en', description: 'A long description.');

        $this->assertFalse($row->isEmpty());
        $this->assertTrue($this->translations()->writeDecision($row)->isAllowed());
    }

    public function testAReplacementThatKeepsTheTitleIsAllowed(): void
    {
        $this->assertTrue(
            $this->translations()->replaceDecision(
                $this->translation('en', 'Concert'),
                $this->translation('en', 'The Concert')
            )->isAllowed()
        );
    }

    public function testATranslationMustNameALocale(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new EventTranslation(eventId: 1, locale: '  '),
            'an empty locale must be refused'
        );
    }

    public function testTwoRowsForTheSameLocaleAreRecognised(): void
    {
        $this->assertTrue($this->translation('EN')->sameLocaleAs($this->translation('en')));
        $this->assertFalse($this->translation('de')->sameLocaleAs($this->translation('en')));
    }
}
