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

namespace Alto\Markdown\Tests\Render;

use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlInlineRenderer;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\PlainInlineText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlainInlineTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function cells(): iterable
    {
        foreach ([
            'plain' => 'hello world',
            'trailing spaces' => 'hello   ',
            'leading spaces' => '   hello',
            'gt and quote' => 'a > b and "c"',
            'ampersand' => 'Tom & Jerry',
            'emphasis' => 'a *b* c',
            'code' => 'use `x`',
            'link' => 'see [x](http://e.co)',
            'strike' => 'a ~~b~~ c',
            'www autolink' => 'visit www.example.com now',
            'http autolink' => 'visit http://example.com',
            'email' => 'mail me@example.com',
            'entity' => 'AT&amp;T',
            'backslash' => 'a \\* b',
            'digits' => '42',
            'punctuation only' => '(a, b): c; d?',
            'image' => '![alt](http://e.co/i.png)',
            'angle bracket autolink' => 'go <http://e.co>',
            'numeric entity' => 'AT&#65;T',
            'standalone underscore emphasis' => 'a _b_ c',
        ] as $name => $text) {
            yield $name => [$text];
        }
    }

    #[DataProvider('cells')]
    public function testFastPathMatchesFullParseWhenItFires(string $text): void
    {
        $doc = Markdown::gfm()->fromString("x\n");
        $model = $doc->model();
        self::assertInstanceOf(\Alto\Markdown\Document\ParsedDocumentModel::class, $model);

        $fast = PlainInlineText::render($text, $model->compiledProfile());
        if (null === $fast) {
            $this->addToAssertionCount(1);

            return; // fast path correctly declined; full parse handles it
        }

        self::assertSame(new HtmlInlineRenderer()->renderMarkdown($model, $text, HtmlPolicy::spec()), $fast);
    }

    public function testDeclinesOnGfmAutolinkAndStrikeButAcceptsPlain(): void
    {
        $model = Markdown::gfm()->fromString("x\n")->model();
        self::assertInstanceOf(\Alto\Markdown\Document\ParsedDocumentModel::class, $model);
        $p = $model->compiledProfile();

        self::assertNull(PlainInlineText::render('www.example.com', $p));
        self::assertNull(PlainInlineText::render('a ~~b~~', $p));
        self::assertSame('hello', PlainInlineText::render('hello', $p));
        self::assertSame('a &gt; b', PlainInlineText::render('a > b', $p));
    }

    /**
     * Hard-wrapped prose: the block spans several line pairs, so the fast
     * path has to reproduce the soft-break joint itself. Both lanes must
     * agree, and the counters say which path actually ran: the document
     * lane parses an inline tape on fallback, the direct lane builds an
     * InlineContent.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function wrappedBlocks(): iterable
    {
        yield 'wrapped plain paragraph' => [
            "alpha beta\ngamma delta\nepsilon zeta\n",
            "<p>alpha beta\ngamma delta\nepsilon zeta</p>\n",
            true,
        ];

        yield 'wrapped soft break after one trailing space' => [
            "alpha beta \ngamma delta\n",
            "<p>alpha beta\ngamma delta</p>\n",
            true,
        ];

        yield 'wrapped keeps a trailing tab before the joint' => [
            "alpha beta\t\ngamma delta\n",
            "<p>alpha beta\t\ngamma delta</p>\n",
            true,
        ];

        yield 'wrapped trims the block-final whitespace' => [
            "alpha beta\ngamma delta   \n",
            "<p>alpha beta\ngamma delta</p>\n",
            true,
        ];

        yield 'wrapped drops the continuation indent' => [
            "alpha beta\n   gamma delta\n",
            "<p>alpha beta\ngamma delta</p>\n",
            true,
        ];

        yield 'wrapped escapes across the joint' => [
            "a > b\nc \"d\"\n",
            "<p>a &gt; b\nc &quot;d&quot;</p>\n",
            true,
        ];

        yield 'wrapped inside a block quote' => [
            "> alpha beta\n> gamma delta\n",
            "<blockquote>\n<p>alpha beta\ngamma delta</p>\n</blockquote>\n",
            true,
        ];

        yield 'wrapped setext heading' => [
            "alpha beta\ngamma delta\n===\n",
            "<h1>alpha beta\ngamma delta</h1>\n",
            true,
        ];

        yield 'wrapped autolink trigger split by the joint' => [
            "www\n.example.com\n",
            "<p>www\n.example.com</p>\n",
            true,
        ];

        yield 'wrapped hard break from two trailing spaces' => [
            "alpha beta  \ngamma delta\n",
            "<p>alpha beta<br />\ngamma delta</p>\n",
            false,
        ];

        yield 'wrapped hard break from four trailing spaces' => [
            "alpha beta    \ngamma delta\n",
            "<p>alpha beta<br />\ngamma delta</p>\n",
            false,
        ];

        yield 'wrapped hard break from a trailing backslash' => [
            "alpha beta\\\ngamma delta\n",
            "<p>alpha beta<br />\ngamma delta</p>\n",
            false,
        ];

        yield 'wrapped with one emphasis special byte' => [
            "alpha *beta*\ngamma delta\n",
            "<p>alpha <em>beta</em>\ngamma delta</p>\n",
            false,
        ];

        yield 'wrapped with one code span on the second line' => [
            "alpha beta\ngamma `delta`\n",
            "<p>alpha beta\ngamma <code>delta</code></p>\n",
            false,
        ];

        yield 'wrapped with one content-scan trigger' => [
            "alpha beta\nwww.example.com\n",
            "<p>alpha beta\n<a href=\"http://www.example.com\">www.example.com</a></p>\n",
            false,
        ];
    }

    #[DataProvider('wrappedBlocks')]
    public function testWrappedBlocksTakeThePlainPathWhenTheyArePlain(string $markdown, string $html, bool $plain): void
    {
        $factory = Markdown::gfm();

        Instrumentation::reset();
        $document = $factory->fromString($markdown)->toHtml();
        $inlineParses = Instrumentation::$inlineParses;

        Instrumentation::reset();
        $direct = $factory->toHtml($markdown);
        $inlineContentBuilds = Instrumentation::$inlineContentBuilds;
        Instrumentation::reset();

        self::assertSame($html, $document);
        self::assertSame($html, $direct);
        self::assertSame($plain ? 0 : 1, $inlineParses);
        self::assertSame($plain ? 0 : 1, $inlineContentBuilds);
    }
}
