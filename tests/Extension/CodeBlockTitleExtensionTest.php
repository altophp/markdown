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

namespace Alto\Markdown\Tests\Extension;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\CodeBlockTitle\CodeBlockTitleExtension;
use Alto\Markdown\Extension\CodeBlockTitle\CodeBlockTitleInfoParser;
use Alto\Markdown\Extension\CodeBlockTitle\CodeBlockTitlePolicy;
use Alto\Markdown\Extension\DefaultAttributes\DefaultAttributesExtension;
use Alto\Markdown\Markdown;
use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Render\HtmlPolicy;
use Alto\Markdown\Render\RenderOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeBlockTitleExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        Instrumentation::reset();
    }

    public function testWrapsOnlyTitledFencedCodeInBothLanesAndPreservesMarkdown(): void
    {
        $factory = Markdown::commonmark()->with(new CodeBlockTitleExtension());
        $source = "```php title=\"src/App.php\"\n<?php echo 1;\n```\n\n"
            . "```php\nplain();\n```\n\n"
            . "    indented();\n";
        $expected = '<figure class="code-block has-title" data-title="src/App.php">' . "\n"
            . '<figcaption class="code-title">src/App.php</figcaption>' . "\n"
            . '<pre><code class="language-php">&lt;?php echo 1;' . "\n"
            . '</code></pre>' . "\n"
            . '</figure>' . "\n"
            . '<pre><code class="language-php">plain();' . "\n"
            . '</code></pre>' . "\n"
            . '<pre><code>indented();' . "\n"
            . '</code></pre>' . "\n";

        self::assertSame($expected, $factory->toHtml($source));
        self::assertSame($expected, $factory->fromString($source)->toHtml());
        self::assertSame($source, $factory->fromString($source)->toMarkdown());
    }

    public function testParsesQuotedUnquotedDecodedAndDuplicateTitlesDeterministically(): void
    {
        $factory = Markdown::commonmark()->with(new CodeBlockTitleExtension());
        $source = "```php filename=\"fallback.php\" title=\"My File.php\"\na\n```\n\n"
            . "```php title='Single quoted.php'\nb\n```\n\n"
            . "```php title=first.php title=last.php\nc\n```\n\n"
            . "```php linenos title=\"A\\* &amp; B\"\nd\n```\n";
        $html = $factory->toHtml($source);

        self::assertStringContainsString('<figcaption class="code-title">My File.php</figcaption>', $html);
        self::assertStringContainsString('<figcaption class="code-title">Single quoted.php</figcaption>', $html);
        self::assertStringContainsString('<figcaption class="code-title">last.php</figcaption>', $html);
        self::assertStringContainsString('<figcaption class="code-title">A* &amp; B</figcaption>', $html);
        self::assertSame(4, substr_count($html, '<figure'));
    }

    public function testTitleAlwaysTakesPrecedenceOverFilename(): void
    {
        $factory = Markdown::commonmark()->with(new CodeBlockTitleExtension());

        self::assertStringContainsString(
            '<figcaption class="code-title">preferred.php</figcaption>',
            $factory->toHtml(
                "```php title=\"preferred.php\" filename=\"later.php\"\ncode\n```\n",
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedInfoStrings(): iterable
    {
        yield 'no title' => ['php'];
        yield 'unterminated double quote' => ['php title="unterminated'];
        yield 'unterminated single quote' => ["php title='unterminated"];
        yield 'missing value' => ['php title='];
        yield 'trailing bytes after quote' => ['php title="valid"tail'];
        yield 'later duplicate is malformed' => ['php title="valid" title='];
    }

    #[DataProvider('malformedInfoStrings')]
    public function testMalformedOrMissingTitleKeepsTheNormalCodeBlock(string $info): void
    {
        $factory = Markdown::commonmark()->with(new CodeBlockTitleExtension());
        $html = $factory->toHtml('```' . $info . "\ncode\n```\n");

        self::assertStringStartsWith('<pre><code', $html);
        self::assertStringNotContainsString('<figure', $html);
    }

    public function testParserLimitsInfoTitleAndAttributeCount(): void
    {
        $policy = new CodeBlockTitlePolicy(maxInfoBytes: 32, maxTitleBytes: 8);

        self::assertNull(CodeBlockTitleInfoParser::title('php ', $policy));
        self::assertSame('x.php', CodeBlockTitleInfoParser::title('php ! title=x.php', $policy));
        self::assertNull(CodeBlockTitleInfoParser::title('php ' . str_repeat('x', 40), $policy));
        self::assertNull(CodeBlockTitleInfoParser::title('php title="123456789"', $policy));
        self::assertNull(CodeBlockTitleInfoParser::title(
            'php ' . implode(' ', array_fill(0, 65, 'flag')),
            new CodeBlockTitlePolicy(maxInfoBytes: 1024),
        ));
    }

    public function testWorksInsideBlockQuotesAndListsWithoutParsingCode(): void
    {
        $factory = Markdown::commonmark()->with(new CodeBlockTitleExtension());
        $source = "> ```html title=\"quoted.html\"\n"
            . "> <strong>literal</strong>\n"
            . "> ```\n\n"
            . "- ```php title=\"item.php\"\n"
            . "  echo '**literal**';\n"
            . "  ```\n";
        $html = $factory->toHtml($source);

        self::assertStringContainsString("<blockquote>\n<figure", $html);
        self::assertStringContainsString('&lt;strong&gt;literal&lt;/strong&gt;', $html);
        self::assertStringContainsString("<li>\n<figure", $html);
        self::assertStringContainsString("echo '**literal**';", $html);
        self::assertSame(2, substr_count($html, '<figure'));
    }

    public function testEscapesHostileTitleAndSurvivesCuratedRendering(): void
    {
        $factory = Markdown::commonmark()->with(new CodeBlockTitleExtension());
        $source = "```html title='<img src=x onerror=alert(1)> &amp; \"quote\"'\ncode\n```\n";
        $expectedTitle = '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;quote&quot;';
        $safe = $factory->toHtml($source);

        self::assertStringContainsString('data-title="' . $expectedTitle . '"', $safe);
        self::assertStringContainsString('>' . $expectedTitle . '</figcaption>', $safe);

        if (class_exists(\Dom\HTMLDocument::class)) {
            $curated = $factory->toHtml(
                $source,
                renderOptions: new RenderOptions(htmlPolicy: HtmlPolicy::curated()),
            );

            self::assertStringContainsString('<figure class="code-block has-title"', $curated);
            self::assertStringContainsString('<figcaption class="code-title">', $curated);
            self::assertStringContainsString('data-title="', $curated);

            $document = \Dom\HTMLDocument::createFromString($curated);
            self::assertNull($document->querySelector('img'));
        }
    }

    public function testCustomPolicyAndDefaultAttributesComposeBeforeTheWrapper(): void
    {
        $factory = Markdown::commonmark()->with(
            new DefaultAttributesExtension([
                'fenced-code' => ['class' => ['default', 'language-php'], 'data-copy' => true],
            ]),
            new CodeBlockTitleExtension(new CodeBlockTitlePolicy(
                figureClass: '',
                captionClass: '',
                includeDataTitle: false,
            )),
        );

        self::assertSame(
            "<figure>\n"
            . "<figcaption>file.php</figcaption>\n"
            . '<pre><code class="default language-php" data-copy>code' . "\n"
            . '</code></pre>' . "\n"
            . '</figure>' . "\n",
            $factory->toHtml("```php title=\"file.php\"\ncode\n```\n"),
        );
    }

    public function testDocumentsWithoutFencesDispatchNothing(): void
    {
        $factory = Markdown::commonmark()->with(new CodeBlockTitleExtension());

        Instrumentation::reset();
        self::assertSame("<p>Plain.</p>\n", $factory->toHtml("Plain.\n"));
        self::assertSame(0, Instrumentation::$htmlDecoratorInvocations);
    }

    public function testDecoratedRenderingMaterializesCodeOnceInBothLanes(): void
    {
        $factory = Markdown::commonmark()->with(new CodeBlockTitleExtension());
        $source = "```php title=\"file.php\"\ncode\n```\n";

        Instrumentation::reset();
        $factory->toHtml($source);
        self::assertSame(1, Instrumentation::$codeBlockCodeReads);

        $document = $factory->fromString($source);
        Instrumentation::reset();
        $document->toHtml();
        self::assertSame(1, Instrumentation::$codeBlockCodeReads);
    }

    public function testDocumentEditsReuseOriginalTitleWithCurrentCodeAndLanguage(): void
    {
        $factory = Markdown::commonmark()->with(new CodeBlockTitleExtension());
        $document = $factory->fromString("```php title=\"file.php\"\nold();\n```\n");
        $block = $document->codeBlocks()->first();
        self::assertNotNull($block);

        $block = $block->replaceCode("newCode();\n")->setLanguage('js');

        self::assertSame(
            '<figure class="code-block has-title" data-title="file.php">' . "\n"
            . '<figcaption class="code-title">file.php</figcaption>' . "\n"
            . '<pre><code class="language-js">newCode();' . "\n"
            . '</code></pre>' . "\n"
            . '</figure>' . "\n",
            $document->toHtml(),
        );
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero info' => [0, 1];
        yield 'negative info' => [-1, 1];
        yield 'zero title' => [10, 0];
        yield 'negative title' => [10, -1];
        yield 'title above info' => [10, 11];
    }

    #[DataProvider('invalidLimits')]
    public function testRejectsInvalidLimits(int $info, int $title): void
    {
        $this->expectException(InvalidMarkdownArgumentException::class);

        new CodeBlockTitlePolicy(maxInfoBytes: $info, maxTitleBytes: $title);
    }
}
