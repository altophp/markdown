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

namespace Alto\Markdown\Tests\Formatter;

use Alto\Markdown\Markdown;
use Alto\Markdown\MarkdownFactory;
use Alto\Markdown\Operation\DisjointPatchLowerer;
use Alto\Markdown\Profile\GfmProfile;
use Alto\Markdown\Profile\ProfileCompiler;
use Alto\Markdown\Tests\Conformance\SpecExample;
use Alto\Markdown\Tests\Conformance\TestHtmlRenderer;
use Alto\Markdown\Tests\Support\MarkdownCorpus;
use Alto\Markdown\Tests\Support\SemanticTreeComparator;
use PHPUnit\Framework\TestCase;

final class FormatterCorpusTest extends TestCase
{
    public function testCommonMarkAndGfmExamplesStayConformantAndReachFixedPoint(): void
    {
        $commonMarkRenderer = new TestHtmlRenderer();
        $gfmRenderer = new TestHtmlRenderer((new ProfileCompiler())->compile(new GfmProfile()));
        $failures = [];

        foreach (MarkdownCorpus::commonMarkExamples() as $example) {
            $failure = $this->specFailure(Markdown::commonmark(), $commonMarkRenderer, $example);

            if (null !== $failure) {
                $failures[] = 'commonmark ' . $failure;
            }
        }

        foreach (MarkdownCorpus::gfmExamples() as $example) {
            $renderer = 'Autolinks' === $example->section ? $commonMarkRenderer : $gfmRenderer;
            $failure = $this->specFailure(Markdown::gfm(), $renderer, $example);

            if (null !== $failure) {
                $failures[] = 'gfm ' . $failure;
            }
        }

        self::assertSame(
            [],
            $failures,
            \sprintf(
                "Formatter corpus failures across %d CommonMark and %d GFM examples:\n%s",
                MarkdownCorpus::COMMONMARK_EXAMPLES,
                MarkdownCorpus::GFM_EXAMPLES,
                implode("\n", $failures),
            ),
        );
    }

    public function testRealAndGeneratedCorpusPreservesSemanticsAndReachesFixedPoint(): void
    {
        $failures = [];

        foreach (MarkdownCorpus::realAndGeneratedDocuments() as $name => $source) {
            try {
                $original = Markdown::github()->fromString($source);
                $first = $this->formatOnce(Markdown::github(), $source);
                $formatted = Markdown::github()->fromString($first['bytes']);
                $comparison = (new SemanticTreeComparator())->compare($original->model(), $formatted->model());

                if (!$comparison->isEqual()) {
                    $failures[] = $name . ': ' . $comparison->message();
                }

                if ($original->toHtml() !== $formatted->toHtml()) {
                    $failures[] = $name . ': product HTML changed after formatting.';
                }

                $fixedPoint = $this->fixedPointFailure(Markdown::github(), $first['bytes']);

                if (null !== $fixedPoint) {
                    $failures[] = $name . ': ' . $fixedPoint;
                }

                if ([] !== $first['fallbacks']) {
                    $failures[] = $name . ': formatter unexpectedly used patch fallback.';
                }
            } catch (\Throwable $error) {
                $failures[] = $name . ': ' . $error::class . ': ' . $error->getMessage();
            }
        }

        self::assertSame(
            [],
            $failures,
            \sprintf(
                "Formatter failures across %d real and generated documents:\n%s",
                MarkdownCorpus::REAL_AND_GENERATED_DOCUMENTS,
                implode("\n", $failures),
            ),
        );
    }

    public function testConformingCorpusProducesNoOperationsOrDiff(): void
    {
        $failures = [];

        foreach (MarkdownCorpus::conformingDocuments() as $name => $source) {
            try {
                $document = Markdown::github()->fromString($source);
                $document->format();
                $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

                if (!$document->model()->journal()->isEmpty()) {
                    $failures[] = $name . ': conforming input produced operations.';
                }

                if (!$document->diff()->isEmpty()) {
                    $failures[] = $name . ': conforming input produced a diff.';
                }

                if ([] !== $lowered->patches || $source !== $lowered->bytes) {
                    $failures[] = $name . ': conforming input lowered to changed bytes.';
                }
            } catch (\Throwable $error) {
                $failures[] = $name . ': ' . $error::class . ': ' . $error->getMessage();
            }
        }

        self::assertSame(
            [],
            $failures,
            \sprintf(
                "Formatter no-op failures across %d conforming documents:\n%s",
                MarkdownCorpus::CONFORMING_DOCUMENTS,
                implode("\n", $failures),
            ),
        );
    }

    public function testRealReadmeDiffMatchesReviewedFixture(): void
    {
        $source = $this->fixture('readme-symfony.md');
        $expectedDiff = $this->fixture('formatter/readme-symfony.diff');
        $document = Markdown::github()->fromString($source);

        $document->format();

        $actualDiff = str_replace("\n \n", "\n\n", $document->diff()->toUnifiedString());

        self::assertSame($expectedDiff, $actualDiff);
    }

    private function specFailure(MarkdownFactory $factory, TestHtmlRenderer $renderer, SpecExample $example): ?string
    {
        try {
            $first = $this->formatOnce($factory, $example->markdown);
            $actualHtml = $renderer->render($first['bytes']);

            if ($example->html !== $actualHtml) {
                return \sprintf('example %d (%s) changed conformance HTML.', $example->example, $example->section);
            }

            if ([] !== $first['fallbacks']) {
                return \sprintf('example %d (%s) used patch fallback.', $example->example, $example->section);
            }

            $fixedPoint = $this->fixedPointFailure($factory, $first['bytes']);

            if (null !== $fixedPoint) {
                return \sprintf('example %d (%s): %s', $example->example, $example->section, $fixedPoint);
            }
        } catch (\Throwable $error) {
            return \sprintf('example %d (%s): %s: %s', $example->example, $example->section, $error::class, $error->getMessage());
        }

        return null;
    }

    private function fixedPointFailure(MarkdownFactory $factory, string $formattedBytes): ?string
    {
        $second = $this->formatOnce($factory, $formattedBytes);

        if ($formattedBytes !== $second['bytes']) {
            return 'second format pass changed bytes.';
        }

        if (0 !== $second['operations']) {
            return \sprintf('second format pass produced %d operations.', $second['operations']);
        }

        if (!$second['diffEmpty']) {
            return 'second format pass produced a diff.';
        }

        if ([] !== $second['patches'] || [] !== $second['fallbacks']) {
            return 'second format pass lowered patches or fallbacks.';
        }

        return null;
    }

    /**
     * @return array{
     *     bytes: string,
     *     operations: int,
     *     diffEmpty: bool,
     *     patches: list<\Alto\Markdown\Operation\SourcePatch>,
     *     fallbacks: list<\Alto\Markdown\Operation\PatchLoweringFallback>
     * }
     */
    private function formatOnce(MarkdownFactory $factory, string $source): array
    {
        $document = $factory->fromString($source);
        $document->format();
        $lowered = new DisjointPatchLowerer()->lower($document->model(), $document->model()->journal());

        return [
            'bytes' => $lowered->bytes,
            'operations' => \count($document->model()->journal()->operations()),
            'diffEmpty' => $document->diff()->isEmpty(),
            'patches' => $lowered->patches,
            'fallbacks' => $lowered->fallbacks,
        ];
    }

    private function fixture(string $relativePath): string
    {
        $bytes = file_get_contents(\dirname(__DIR__) . '/fixtures/' . $relativePath);

        if (false === $bytes) {
            throw new \RuntimeException(\sprintf('Unable to read fixture "%s".', $relativePath));
        }

        return $bytes;
    }
}
