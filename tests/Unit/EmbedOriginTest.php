<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Embed\Domain\EmbedOrigin;
use Nabilet\Tests\Support\TestCase;

/**
 * The Origin allow-list rule, tested without a database or a framework.
 *
 * This is the one part of §18's gate that is a pure function, and it is also the
 * part where a mistake produces no error at all: a comparison that is
 * case-sensitive rejects a site the organizer listed, and one that widens a host
 * to its subdomains accepts a site they never listed. Neither shows up as a
 * failure — one becomes a support ticket, the other becomes silence.
 *
 * The tests are written as the two questions the rule actually answers:
 * "what host is this?" and "is that host on the list?".
 */
final class EmbedOriginTest extends TestCase
{
    // ── hostFrom(): what host did this request come from? ────────────────────

    public function test_host_is_extracted_from_a_plain_https_origin(): void
    {
        $this->assertSame('example.com', EmbedOrigin::hostFrom('https://example.com'));
    }

    public function test_scheme_and_port_are_dropped_and_the_host_is_lowercased(): void
    {
        // `https://example.com` and `https://example.com:8443` are the same *site*:
        // the allow-list is a list of sites, not of listening sockets.
        $this->assertSame('example.com', EmbedOrigin::hostFrom('https://Example.COM:8443'));
        $this->assertSame('example.com', EmbedOrigin::hostFrom('http://example.com:80'));
    }

    public function test_path_query_and_userinfo_do_not_change_the_host(): void
    {
        $this->assertSame(
            'example.com',
            EmbedOrigin::hostFrom('https://user:secret@example.com:8443/widget?event=7#seats')
        );
    }

    public function test_a_missing_header_is_unknown_not_a_host(): void
    {
        $this->assertNull(EmbedOrigin::hostFrom(null));
        $this->assertNull(EmbedOrigin::hostFrom(''));
        $this->assertNull(EmbedOrigin::hostFrom('   '));
    }

    public function test_the_literal_null_origin_is_refused(): void
    {
        // A sandboxed iframe or a `data:` document sends `Origin: null`. There is
        // no site the organizer could have listed, so there is nothing to match.
        $this->assertNull(EmbedOrigin::hostFrom('null'));
        $this->assertNull(EmbedOrigin::hostFrom('NULL'));
    }

    public function test_a_bare_hostname_without_a_scheme_is_not_an_origin(): void
    {
        // `Origin` is always an absolute origin. Accepting `example.com` here would
        // mean guessing at a header the browser never sends that way.
        $this->assertNull(EmbedOrigin::hostFrom('example.com'));
        $this->assertNull(EmbedOrigin::hostFrom('//example.com'));
    }

    public function test_a_non_http_scheme_is_refused(): void
    {
        $this->assertNull(EmbedOrigin::hostFrom('ftp://example.com'));
        $this->assertNull(EmbedOrigin::hostFrom('file:///etc/passwd'));
        $this->assertNull(EmbedOrigin::hostFrom('data:text/html,<b>x</b>'));
        $this->assertNull(EmbedOrigin::hostFrom('javascript:alert(1)'));
    }

    public function test_an_unparsable_value_is_unknown(): void
    {
        $this->assertNull(EmbedOrigin::hostFrom('http://'));
    }

    // ── isAllowed(): is that host on the list? ───────────────────────────────

    public function test_a_bare_entry_matches_that_host_exactly(): void
    {
        $this->assertTrue(EmbedOrigin::isAllowed('example.com', ['example.com']));
    }

    public function test_a_bare_entry_does_not_match_a_subdomain(): void
    {
        // The deliberate refusal. `www.example.com` is a different origin and is
        // frequently operated by somebody else; widening a bare entry to its
        // subdomains is how one listed site becomes an open redirect target.
        $this->assertFalse(EmbedOrigin::isAllowed('www.example.com', ['example.com']));
        $this->assertFalse(EmbedOrigin::isAllowed('a.b.example.com', ['example.com']));
    }

    public function test_a_leading_dot_entry_matches_the_host_and_its_subdomains(): void
    {
        $this->assertTrue(EmbedOrigin::isAllowed('example.com', ['.example.com']));
        $this->assertTrue(EmbedOrigin::isAllowed('www.example.com', ['.example.com']));
        $this->assertTrue(EmbedOrigin::isAllowed('a.b.example.com', ['.example.com']));
    }

    public function test_a_leading_dot_entry_does_not_match_a_lookalike(): void
    {
        // `str_ends_with` is the trap here: `notexample.com` ends with
        // `example.com` but not with `.example.com`, and `example.com.evil.com`
        // ends with neither. Both must be refused.
        $this->assertFalse(EmbedOrigin::isAllowed('notexample.com', ['.example.com']));
        $this->assertFalse(EmbedOrigin::isAllowed('example.com.evil.com', ['.example.com']));
        $this->assertFalse(EmbedOrigin::isAllowed('evil-example.com', ['.example.com']));
    }

    public function test_an_empty_list_allows_nothing(): void
    {
        $this->assertFalse(EmbedOrigin::isAllowed('example.com', []));
    }

    public function test_an_empty_host_allows_nothing(): void
    {
        $this->assertFalse(EmbedOrigin::isAllowed('', ['example.com']));
        $this->assertFalse(EmbedOrigin::isAllowed('   ', ['.example.com']));
    }

    public function test_an_empty_entry_is_skipped_rather_than_matching_everything(): void
    {
        // A blank row in `embed_domains` must not become a wildcard.
        $this->assertFalse(EmbedOrigin::isAllowed('example.com', ['']));
        $this->assertFalse(EmbedOrigin::isAllowed('example.com', ['', '   ']));
    }

    public function test_matching_is_case_insensitive_and_trims_whitespace(): void
    {
        $this->assertTrue(EmbedOrigin::isAllowed('EXAMPLE.com', ['example.com']));
        $this->assertTrue(EmbedOrigin::isAllowed('example.com', [' Example.COM ']));
        $this->assertTrue(EmbedOrigin::isAllowed('WWW.Example.com', ['.example.com']));
    }

    public function test_any_matching_entry_wins(): void
    {
        $this->assertTrue(EmbedOrigin::isAllowed('a.example.com', ['other.com', '.example.com']));
        $this->assertTrue(EmbedOrigin::isAllowed('other.com', ['other.com', '.example.com']));
    }

    public function test_the_dot_entry_and_the_bare_entry_are_not_interchangeable(): void
    {
        // Stated as a pair so the difference cannot be lost in a refactor: one list
        // admits the subdomain, the other does not, and that is the whole point.
        $this->assertTrue(EmbedOrigin::isAllowed('www.example.com', ['.example.com']));
        $this->assertFalse(EmbedOrigin::isAllowed('www.example.com', ['example.com']));
    }
}
