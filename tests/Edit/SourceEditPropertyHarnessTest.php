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

namespace Alto\Markdown\Tests\Edit;

use Alto\Markdown\Markdown;
use Alto\Markdown\Source\LineEnding;
use Alto\Markdown\Source\SourceDocument;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Tests\Support\SourceEditExpectation;
use Alto\Markdown\Tests\Support\SourceEditPropertyHarness;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class SourceEditPropertyHarnessTest extends TestCase
{
    public function testExpressesFidelityMinimalityAndPreservation(): void
    {
        $original = "# Title\n\nOld body.\n\n## Usage\n\nKeep this.\n";
        $edited = "# Title\n\nNew body.\n\n## Usage\n\nKeep this.\n";

        new SourceEditPropertyHarness()->assertProperties(
            new SourceEditExpectation(
                originalBytes: $original,
                editedBytes: $edited,
                expectedSemanticBytes: $edited,
                originalTouchedRanges: [self::rangeFor($original, 'Old body.')],
                editedTouchedRanges: [self::rangeFor($edited, 'New body.')],
            ),
            Markdown::github(),
        );
    }

    public function testPreservationCatchesUntouchedByteChanges(): void
    {
        $original = "# Title\n\nOld body.\n\nKeep this.\n";
        $edited = "# Title\n\nNew body.\n\nKeep that.\n";

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Untouched byte segments must stay byte-identical.');

        new SourceEditPropertyHarness()->assertPreservation(new SourceEditExpectation(
            originalBytes: $original,
            editedBytes: $edited,
            expectedSemanticBytes: $edited,
            originalTouchedRanges: [self::rangeFor($original, 'Old body.')],
            editedTouchedRanges: [self::rangeFor($edited, 'New body.')],
        ));
    }

    public function testMinimalityCoversCrlfAndBomFixtures(): void
    {
        $bom = "\xEF\xBB\xBF";
        $original = "{$bom}# Title\r\n\r\nOld body.\r\n\r\n## Usage\r\n\r\nKeep this.\r\n";
        $edited = "{$bom}# Title\r\n\r\nNew body.\r\n\r\n## Usage\r\n\r\nKeep this.\r\n";

        new SourceEditPropertyHarness()->assertProperties(
            new SourceEditExpectation(
                originalBytes: $original,
                editedBytes: $edited,
                expectedSemanticBytes: $edited,
                originalTouchedRanges: [self::rangeFor($original, 'Old body.')],
                editedTouchedRanges: [self::rangeFor($edited, 'New body.')],
            ),
            Markdown::github(),
        );
    }

    public function testAssertsByteRangesAgainstSourceDocument(): void
    {
        $bytes = "\xEF\xBB\xBF# Title\r\n\r\nBody\r\n";

        new SourceEditPropertyHarness()->assertSourceRangeBytes(
            new SourceDocument($bytes, LineEnding::CrLf, true),
            self::rangeFor($bytes, 'Body'),
            'Body',
        );
    }

    public function testMinimalityCatchesChangesOutsideEditedLineRanges(): void
    {
        $original = "# Title\n\nOld body.\n\nKeep this.\n";
        $edited = "# Title\n\nNew body.\n\nKeep that.\n";

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Original line 5 changed outside the edited block range.');

        new SourceEditPropertyHarness()->assertMinimality(new SourceEditExpectation(
            originalBytes: $original,
            editedBytes: $edited,
            expectedSemanticBytes: $edited,
            originalTouchedRanges: [self::rangeFor($original, 'Old body.')],
            editedTouchedRanges: [self::rangeFor($edited, 'New body.')],
        ));
    }

    public function testUnifiedDiffFixtureHelper(): void
    {
        new SourceEditPropertyHarness()->assertUnifiedDiffFixture(
            <<<'DIFF'
                --- before.md
                +++ after.md
                @@ -1,3 +1,3 @@
                 a
                -b
                +B
                 c

                DIFF,
            "a\nb\nc\n",
            "a\nB\nc\n",
            'before.md',
            'after.md',
        );
    }

    private static function rangeFor(string $bytes, string $needle): SourceRange
    {
        $start = strpos($bytes, $needle);

        self::assertIsInt($start);

        return new SourceRange($start, $start + \strlen($needle));
    }
}
