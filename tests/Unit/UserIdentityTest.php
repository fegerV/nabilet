<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Users\Domain\ContactChannel;
use Nabilet\Modules\Users\Domain\IdentityDecision;
use Nabilet\Modules\Users\Domain\UserIdentity;
use Nabilet\Modules\Users\Domain\UserIdentityPolicy;
use Nabilet\Tests\Support\TestCase;

/**
 * The lifecycle of an account's identity (ТЗ §5, §6).
 *
 * Every gap these rules close was reproduced against MySQL 8.4 first, in
 * `tools/repro-user-identity.sql`:
 *
 *   - a soft-deleted account kept its address: a new INSERT with the same email
 *     died with `ERROR 1062`, because `uq_users_email` does not include `deleted_at`;
 *   - `UPDATE users SET email = 'other@example.com'` left `email_verified_at`
 *     untouched, and so did `email = NULL` — an account verified for an address
 *     nobody confirmed, or for no address at all;
 *   - a row with `email`, `phone` and `password` all NULL was accepted;
 *   - `A@EXAMPLE.COM` and `a@example.com ` both collided with `a@example.com`,
 *     because `utf8mb4_unicode_ci` is case-insensitive and PAD SPACE;
 *   - anonymising in place (nulling the columns) DID release the address — the
 *     remedy works, which is why `anonymised()` exists.
 */
final class UserIdentityTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-01 12:00:00');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function identity(
        ?string $email = 'a@example.com',
        ?\DateTimeImmutable $emailVerifiedAt = null,
        ?string $phone = null,
        ?\DateTimeImmutable $phoneVerifiedAt = null,
        ?string $passwordHash = 'hash',
        ?\DateTimeImmutable $deletedAt = null,
        int|string $id = 1,
    ): UserIdentity {
        return new UserIdentity(
            id: $id,
            email: $email,
            emailVerifiedAt: $emailVerifiedAt,
            phone: $phone,
            phoneVerifiedAt: $phoneVerifiedAt,
            passwordHash: $passwordHash,
            firstName: 'Ivan',
            lastName: 'Petrov',
            deletedAt: $deletedAt,
        );
    }

    private function policy(): UserIdentityPolicy
    {
        return new UserIdentityPolicy();
    }

    // ── comparison is the database's decision ────────────────────────────────

    public function testAnEmailIsComparedCaseInsensitively(): void
    {
        // utf8mb4_unicode_ci: 'A@EXAMPLE.COM' collided with 'a@example.com' (1062).
        $this->assertSame(
            'a@example.com',
            ContactChannel::normalise(ContactChannel::EMAIL, 'A@EXAMPLE.COM')
        );
    }

    public function testTrailingWhitespaceIsIgnored(): void
    {
        // PAD SPACE: 'a@example.com ' collided with 'a@example.com' (1062).
        $this->assertSame(
            'a@example.com',
            ContactChannel::normalise(ContactChannel::EMAIL, "a@example.com \n")
        );
    }

    public function testCaseAndWhitespaceDoNotMakeANewPerson(): void
    {
        $this->assertTrue(
            ContactChannel::sameIdentity(ContactChannel::EMAIL, 'a@example.com', 'A@EXAMPLE.COM')
        );
        $this->assertTrue(
            ContactChannel::sameIdentity(ContactChannel::EMAIL, 'a@example.com', 'a@example.com ')
        );
        $this->assertFalse(
            ContactChannel::sameIdentity(ContactChannel::EMAIL, 'a@example.com', 'b@example.com')
        );
    }

    public function testAPhoneIsTrimmedButNotStripped(): void
    {
        // Stripping separators would make PHP stricter than the column:
        // '+7 900 000 00 00' and '+79000000000' are two different rows to MySQL.
        $this->assertSame('+7 900 000 00 00', ContactChannel::normalise(ContactChannel::PHONE, ' +7 900 000 00 00 '));
        $this->assertFalse(
            ContactChannel::sameIdentity(ContactChannel::PHONE, '+7 900 000 00 00', '+79000000000')
        );
    }

    public function testAnEmptyContactIsTreatedAsNone(): void
    {
        $this->assertNull(ContactChannel::normalise(ContactChannel::EMAIL, '   '));
        $this->assertNull(ContactChannel::normalise(ContactChannel::PHONE, ''));
    }

    public function testAnUnknownChannelIsRefused(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => ContactChannel::normalise('telegram', '@someone'),
            'an unknown channel must be refused'
        );
    }

    // ── reachability ────────────────────────────────────────────────────────

    public function testAnAccountWithAnEmailIsReachable(): void
    {
        $this->assertTrue($this->identity()->isReachable());
    }

    public function testAnAccountWithOnlyAPhoneIsReachable(): void
    {
        $this->assertTrue($this->identity(email: null, phone: '+79000000000')->isReachable());
    }

    public function testAnAccountWithNeitherIsUnreachable(): void
    {
        // The row MySQL accepted: email, phone and password all NULL. The bundle
        // has no OAuth/social table, so nobody can ever reach this account.
        $nobody = new UserIdentity(id: 1, firstName: 'Ivan', lastName: 'Petrov');

        $this->assertFalse($nobody->isReachable());
        $this->assertFalse($nobody->hasPassword());
    }

    public function testAWhitespaceOnlyEmailDoesNotMakeAnAccountReachable(): void
    {
        $this->assertFalse($this->identity(email: "  \n")->isReachable());
    }

    // ── verification follows the contact ────────────────────────────────────

    public function testChangingTheEmailClearsTheVerification(): void
    {
        $before = $this->identity(emailVerifiedAt: $this->now);
        $after = $before->withEmail('other@example.com');

        $this->assertNotNull($before->emailVerifiedAt);
        $this->assertNull($after->emailVerifiedAt);
        $this->assertSame('other@example.com', $after->email);
    }

    public function testReSavingTheSameEmailKeepsTheVerification(): void
    {
        // NO_CHANGE is not work. Saving a profile form that did not touch the
        // address must not de-verify the entire user base.
        $after = $this->identity(emailVerifiedAt: $this->now)->withEmail('a@example.com');

        $this->assertSame($this->now, $after->emailVerifiedAt);
    }

    public function testChangingOnlyTheCaseKeepsTheVerification(): void
    {
        // The column cannot tell these apart, so neither may the rule: MySQL
        // accepted one row for both, and de-verifying here would de-verify a
        // person who changed nothing.
        $after = $this->identity(emailVerifiedAt: $this->now)->withEmail('A@EXAMPLE.COM');

        $this->assertSame($this->now, $after->emailVerifiedAt);
    }

    public function testTrailingWhitespaceAloneKeepsTheVerification(): void
    {
        $after = $this->identity(emailVerifiedAt: $this->now)->withEmail('a@example.com  ');

        $this->assertSame($this->now, $after->emailVerifiedAt);
        $this->assertSame('a@example.com', $after->email);
    }

    public function testChangingThePhoneClearsItsVerification(): void
    {
        $before = $this->identity(phone: '+79000000000', phoneVerifiedAt: $this->now);
        $after = $before->withPhone('+79111111111');

        $this->assertNull($after->phoneVerifiedAt);
        $this->assertTrue($after->isVerifiedOn(ContactChannel::PHONE) === false);
    }

    public function testClearingTheEmailAlsoClearsTheVerification(): void
    {
        // MySQL accepted `email = NULL` with `email_verified_at` still set — that
        // is the row the audit exists to find. The transition must not produce it:
        // dropping the contact drops the claim about the contact.
        $after = $this->identity(emailVerifiedAt: $this->now)->withEmail(null);

        $this->assertNull($after->email);
        $this->assertNull($after->emailVerifiedAt);
        $this->assertSame([], $after->orphanedVerifications());
        $this->assertFalse($after->isVerifiedOn(ContactChannel::EMAIL));
    }

    public function testTheOrphanTheSchemaAcceptsIsRepresentable(): void
    {
        // The object must be able to HOLD the bad row, otherwise it cannot be
        // loaded in order to be audited. This is the MySQL state, constructed
        // directly rather than reached through a transition.
        $orphan = new UserIdentity(id: 1, emailVerifiedAt: $this->now);

        $this->assertNull($orphan->email);
        $this->assertNotNull($orphan->emailVerifiedAt);
        $this->assertSame([ContactChannel::EMAIL], $orphan->orphanedVerifications());
    }

    public function testOrphanedFlagsAreReportedForBothChannels(): void
    {
        $orphan = new UserIdentity(
            id: 7,
            emailVerifiedAt: $this->now,
            phoneVerifiedAt: $this->now,
        );

        $this->assertSame(
            [ContactChannel::EMAIL, ContactChannel::PHONE],
            $orphan->orphanedVerifications()
        );
    }

    public function testACleanAccountHasNoOrphans(): void
    {
        $clean = $this->identity(
            emailVerifiedAt: $this->now,
            phone: '+79000000000',
            phoneVerifiedAt: $this->now,
        );

        $this->assertSame([], $clean->orphanedVerifications());
        $this->assertTrue($clean->isVerifiedOn(ContactChannel::EMAIL));
        $this->assertTrue($clean->isVerifiedOn(ContactChannel::PHONE));
    }

    public function testVerifyingMarksTheMoment(): void
    {
        $verified = $this->identity()->markEmailVerified($this->now)->markPhoneVerified($this->now);

        $this->assertSame($this->now, $verified->emailVerifiedAt);
        $this->assertSame($this->now, $verified->phoneVerifiedAt);
    }

    // ── deletion and erasure ────────────────────────────────────────────────

    public function testADeletedAccountIsDeleted(): void
    {
        $this->assertTrue($this->identity(deletedAt: $this->now)->isDeleted());
        $this->assertFalse($this->identity()->isDeleted());
    }

    public function testAnonymisingRemovesEveryPersonalField(): void
    {
        $after = $this->identity(emailVerifiedAt: $this->now, phone: '+79000000000', phoneVerifiedAt: $this->now)
            ->anonymised();

        $this->assertNull($after->email);
        $this->assertNull($after->phone);
        $this->assertNull($after->emailVerifiedAt);
        $this->assertNull($after->phoneVerifiedAt);
        $this->assertNull($after->passwordHash);
        $this->assertNull($after->firstName);
        $this->assertNull($after->lastName);
    }

    public function testAnonymisingReleasesTheAddress(): void
    {
        // Proven on MySQL: after nulling the columns, a new account with the same
        // address was ACCEPTED. A soft delete would have kept it pinned.
        $this->assertTrue($this->identity()->anonymised()->releasesContactKeys());
        $this->assertFalse($this->identity()->releasesContactKeys());
    }

    // ── registration ────────────────────────────────────────────────────────

    public function testAFreeAddressCanBeRegistered(): void
    {
        $decision = $this->policy()->registerDecision($this->identity(), false);

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(IdentityDecision::ALLOWED, $decision->verdict);
    }

    public function testAnAddressHeldByALiveAccountIsRefused(): void
    {
        $decision = $this->policy()->registerDecision($this->identity(), true);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IdentityDecision::EMAIL_TAKEN, $decision->verdict);
    }

    public function testAnAddressHeldByADeletedAccountHasItsOwnVerdict(): void
    {
        // MySQL collapses both situations into one 1062. Support cannot tell
        // "somebody is using this" from "nobody is using this" from the error.
        $decision = $this->policy()->registerDecision($this->identity(), true, true);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IdentityDecision::EMAIL_TAKEN_BY_DELETED, $decision->verdict);
        $this->assertStringContainsString('deleted', (string) $decision->reason);
    }

    public function testAnUnreachableCandidateIsRefusedBeforeTheAddressIsConsidered(): void
    {
        // The state of the account outranks the state of the address: an account
        // nobody can reach is wrong regardless of whether the address is free.
        $nobody = new UserIdentity(id: 3);
        $decision = $this->policy()->registerDecision($nobody, true, true);

        $this->assertSame(IdentityDecision::NOT_REACHABLE, $decision->verdict);
    }

    public function testAPhoneOnlyAccountCanBeRegistered(): void
    {
        $decision = $this->policy()->registerDecision(
            $this->identity(email: null, phone: '+79000000000'),
            false
        );

        $this->assertTrue($decision->isAllowed());
    }

    // ── authentication ──────────────────────────────────────────────────────

    public function testALiveReachableAccountMayAuthenticate(): void
    {
        $this->assertTrue($this->policy()->authenticateDecision($this->identity())->isAllowed());
    }

    public function testADeletedAccountMayNotAuthenticate(): void
    {
        // A soft delete that does not lock the account out is not a deletion.
        $decision = $this->policy()->authenticateDecision($this->identity(deletedAt: $this->now));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IdentityDecision::ACCOUNT_DELETED, $decision->verdict);
    }

    public function testAnUnreachableAccountMayNotAuthenticate(): void
    {
        $decision = $this->policy()->authenticateDecision(new UserIdentity(id: 3));

        $this->assertSame(IdentityDecision::NOT_REACHABLE, $decision->verdict);
    }

    // ── auditing rows that predate the rule ─────────────────────────────────

    public function testTheAuditPassesACleanAccount(): void
    {
        $this->assertTrue($this->policy()->verificationDecision(
            $this->identity(emailVerifiedAt: $this->now)
        )->isAllowed());
    }

    public function testTheAuditFindsTheRowTheSchemaAccepted(): void
    {
        // email = NULL, email_verified_at still set — accepted by MySQL.
        $row = new UserIdentity(id: 1, emailVerifiedAt: $this->now, phone: '+79000000000');

        $decision = $this->policy()->verificationDecision($row);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IdentityDecision::ORPHANED_VERIFICATION, $decision->verdict);
    }

    // ── moving an address ───────────────────────────────────────────────────

    public function testMovingAVerifiedAccountToANewAddressIsReported(): void
    {
        $decision = $this->policy()->addressChangeDecision(
            $this->identity(emailVerifiedAt: $this->now),
            'other@example.com'
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IdentityDecision::ORPHANED_VERIFICATION, $decision->verdict);
    }

    public function testReSavingTheSameAddressIsAllowed(): void
    {
        $decision = $this->policy()->addressChangeDecision(
            $this->identity(emailVerifiedAt: $this->now),
            'A@EXAMPLE.COM'
        );

        $this->assertTrue($decision->isAllowed());
    }

    public function testRemovingTheOnlyAddressIsRefused(): void
    {
        $decision = $this->policy()->addressChangeDecision($this->identity(), null);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IdentityDecision::NOT_REACHABLE, $decision->verdict);
    }

    public function testRemovingTheEmailOfAnAccountThatStillHasAPhoneIsAllowed(): void
    {
        $decision = $this->policy()->addressChangeDecision(
            $this->identity(phone: '+79000000000'),
            null
        );

        $this->assertTrue($decision->isAllowed());
    }
}
