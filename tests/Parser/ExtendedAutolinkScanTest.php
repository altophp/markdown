<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

namespace Alto\Markdown\Tests\Parser;

use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Inline\ExtendedAutolinkParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The GFM bare autolink over-approximates its start bytes with every ASCII
 * letter and digit, so it is located by searching for its content triggers
 * ("www.", "://", "@") and walking back to the start, not by stopping the
 * scan loop on every alphanumeric byte. These cases pin the behavior that
 * the walk has to reproduce.
 */
final class ExtendedAutolinkScanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        // Constructs whose trigger byte is in the scan set consume their
        // region first, so the search resumes past it and never sees a
        // candidate inside a code span or inside raw HTML.
        yield 'code span keeps a url literal' => [
            "a `https://example.com` b\n",
            "<p>a <code>https://example.com</code> b</p>\n",
        ];

        yield 'code span keeps an email literal' => [
            "a `user@example.com` b\n",
            "<p>a <code>user@example.com</code> b</p>\n",
        ];

        yield 'raw html attribute keeps a url literal' => [
            "<a href=\"https://example.com\">x</a>\n",
            "<p>&lt;a href=&quot;https://example.com&quot;&gt;x&lt;/a&gt;</p>\n",
        ];

        // Word boundary: a scheme or a "www." inside a word starts nothing.
        yield 'mid word www is literal' => [
            "foowww.example.com bar\n",
            "<p>foowww.example.com bar</p>\n",
        ];

        yield 'mid word scheme is literal' => [
            "xhttp://example.com\n",
            "<p>xhttp://example.com</p>\n",
        ];

        yield 'parenthesis is a boundary' => [
            "(www.example.com)\n",
            "<p>(<a href=\"http://www.example.com\">www.example.com</a>)</p>\n",
        ];

        yield 'emphasis delimiter is a boundary' => [
            "*www.example.com*\n",
            "<p><em><a href=\"http://www.example.com\">www.example.com</a></em></p>\n",
        ];

        // The fiddly tail rules stay where they were.
        yield 'trailing punctuation stays outside' => [
            "www.example.com.\n",
            "<p><a href=\"http://www.example.com\">www.example.com</a>.</p>\n",
        ];

        yield 'trailing entity stays outside' => [
            "www.example.com/a&amp;\n",
            "<p><a href=\"http://www.example.com/a\">www.example.com/a</a>&amp;</p>\n",
        ];

        yield 'unbalanced parenthesis stays outside' => [
            "www.example.com/a(b)c) x\n",
            "<p><a href=\"http://www.example.com/a(b)c\">www.example.com/a(b)c</a>) x</p>\n",
        ];

        yield 'underscore disqualifies the last two domain segments' => [
            "www.foo_bar.example.com and www.foo.bar_baz.com\n",
            "<p><a href=\"http://www.foo_bar.example.com\">www.foo_bar.example.com</a> and www.foo.bar_baz.com</p>\n",
        ];

        yield 'email needs a dotted domain' => [
            "user@localhost\n",
            "<p>user@localhost</p>\n",
        ];

        yield 'email rejects a trailing hyphen' => [
            "user@example.com-\n",
            "<p>user@example.com-</p>\n",
        ];

        // The local part walks back over the run, but an underscore heads
        // an emphasis delimiter the scan claims before any construct.
        yield 'email skips a leading underscore' => [
            "_user@example.com_\n",
            "<p><em>user@example.com</em></p>\n",
        ];

        yield 'email resumes where a backslash escape ended' => [
            "\\.foo@bar.com\n",
            "<p>.<a href=\"mailto:foo@bar.com\">foo@bar.com</a></p>\n",
        ];

        yield 'email resumes where a code span ended' => [
            "`x`bc@def.gh\n",
            "<p><code>x</code><a href=\"mailto:bc@def.gh\">bc@def.gh</a></p>\n",
        ];

        yield 'email starts inside a word' => [
            "xfoo@bar.com\n",
            "<p><a href=\"mailto:xfoo@bar.com\">xfoo@bar.com</a></p>\n",
        ];

        yield 'local part may open with a dot' => [
            ".foo@bar.com\n",
            "<p><a href=\"mailto:.foo@bar.com\">.foo@bar.com</a></p>\n",
        ];

        yield 'local part may open with a plus' => [
            "+tag@mail.co\n",
            "<p><a href=\"mailto:+tag@mail.co\">+tag@mail.co</a></p>\n",
        ];

        yield 'www label loses to the email around it' => [
            "www.foo@bar.com\n",
            "<p><a href=\"mailto:www.foo@bar.com\">www.foo@bar.com</a></p>\n",
        ];

        yield 'autolink survives a soft break' => [
            "see\nhttps://example.com/a\nend\n",
            "<p>see\n<a href=\"https://example.com/a\">https://example.com/a</a>\nend</p>\n",
        ];

        yield 'link label wins over the bare autolink inside it' => [
            "[www.example.com](/u)\n",
            "<p><a href=\"/u\">www.example.com</a></p>\n",
        ];

        yield 'a bare at sign is literal' => [
            "a @ b\n",
            "<p>a @ b</p>\n",
        ];

        yield 'scheme without a dotted domain is literal' => [
            "http://x\n",
            "<p>http://x</p>\n",
        ];

        yield 'uppercase scheme and label are literal' => [
            "WWW.EXAMPLE.COM and HTTP://X.CO\n",
            "<p>WWW.EXAMPLE.COM and HTTP://X.CO</p>\n",
        ];
    }

    #[DataProvider('cases')]
    public function testBothLanesAgreeWithTheByteScanner(string $markdown, string $html): void
    {
        $factory = Markdown::gfm();

        self::assertSame($html, $factory->fromString($markdown)->toHtml());
        self::assertSame($html, $factory->toHtml($markdown));
    }

    /**
     * The candidate search must never report a start behind the cursor and
     * must find every shape the parser can consume.
     */
    public function testNextCandidateLocatesEveryStartShape(): void
    {
        $parser = new ExtendedAutolinkParser();

        self::assertSame(
            'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-+',
            $parser->triggerBytes(),
        );
        self::assertSame(4, $parser->nextCandidate('see www.example.com', 0));
        self::assertSame(4, $parser->nextCandidate('see http://example.com', 0));
        self::assertSame(4, $parser->nextCandidate('see https://example.com', 0));
        self::assertSame(4, $parser->nextCandidate('see ftp://example.com', 0));
        self::assertSame(4, $parser->nextCandidate('see user@example.com', 0));
        self::assertSame(5, $parser->nextCandidate('see _user@example.com', 0));
        self::assertSame(-1, $parser->nextCandidate('plain prose with no candidate', 0));
        self::assertSame(-1, $parser->nextCandidate('a @ b', 0));

        // A scheme whose mark sits behind the cursor is skipped, a local
        // part that starts behind it is clamped to the cursor.
        self::assertSame(-1, $parser->nextCandidate('http://example.com', 1));
        self::assertSame(1, $parser->nextCandidate('user@example.com', 1));
        self::assertSame(13, $parser->nextCandidate('http://a.b x user@c.de', 8));
    }

    public function testUrlWithEmptyFirstDomainSegmentStaysLiteral(): void
    {
        self::assertSame(
            "<p>http://.example</p>\n",
            Markdown::gfm()->toHtml("http://.example\n"),
        );
    }

    /**
     * A paragraph of nothing but declined candidates must stay linear: the
     * search memo and the jump bound both exist for this. The budget is
     * deliberately loose, so it catches an order-of-magnitude regression
     * (the quadratic shape runs about twenty times slower here) without
     * turning machine load into a failure.
     *
     * @return iterable<string, array{string}>
     */
    public static function declinedCandidateFloods(): iterable
    {
        yield 'schemes without a domain' => [str_repeat('http://', 16000)."\n"];
        yield 'locals without a domain' => [str_repeat('a@', 16000)."\n"];
        yield 'labels without a domain' => [str_repeat('www.', 16000)."\n"];
    }

    #[DataProvider('declinedCandidateFloods')]
    public function testDeclinedCandidatesStayLinear(string $markdown): void
    {
        $started = hrtime(true);
        $html = Markdown::gfm()->toHtml($markdown);
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertStringStartsWith('<p>', $html);
        self::assertLessThan(2.0, $elapsed);
    }
}
