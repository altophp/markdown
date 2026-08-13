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

namespace Alto\Markdown\Parser\Block;

use Alto\Markdown\Parser\ParserState;

/**
 * The opaque front matter leaf block (SPEC section 11.7).
 *
 * Front matter is a document-level convention, not Markdown syntax: it is
 * recognized only at the very start of the input (offset 0, or offset 3 after
 * a BOM per SPEC section 9), never after a blank line and never indented. The
 * opening fence is the whole first line, exactly `---` for YAML or `+++` for
 * TOML once trailing spaces and tabs are dropped. The block ends at the first
 * later line that is exactly the same fence under the same rule.
 *
 * Because the start is positional rather than trigger-driven, this construct
 * never takes part in the block loop's start phase: {@see triggerBytes} is the
 * empty string and {@see tryStart} always declines. BlockParser asks
 * {@see opensDocument} once, before the first line is parsed, which is also
 * what gives front matter precedence over the thematic break that `---` would
 * otherwise open.
 *
 * An unterminated block is not opened at all: {@see opensDocument} requires a
 * closing fence to exist. The alternative (open, then run to end of input)
 * would let one stray `---` swallow a whole document, which is exactly the
 * failure an editing tool must not have. Falling back to plain CommonMark also
 * matches what Jekyll, Hugo and github.com do with an unterminated fence.
 *
 * Tape convention read by the renderer and the node handle: flags = the fence
 * byte (0x2D or 0x2B); payload "cs:ce" spans the content lines between the
 * fences (cs == ce when the block is empty). The block's own start and end
 * offsets cover the whole block, both fences included, through the closing
 * fence's line ending, so slicing them reproduces the input byte for byte.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FrontMatterParser implements OpaqueLeafBlock
{
    private const string YAML_FENCE = '---';

    private const string TOML_FENCE = '+++';

    private const int FENCE_LENGTH = 3;

    /**
     * Content bounds of the open block, keyed by ordinal. A document holds at
     * most one front matter block, so these never carry more than one entry.
     *
     * @var array<int, int>
     */
    private array $contentStart = [];

    /**
     * @var array<int, int>
     */
    private array $contentEnd = [];

    public function __construct(private readonly int $kind) {}

    public function kind(): int
    {
        return $this->kind;
    }

    public function triggerBytes(): string
    {
        // Never consulted by the start phase: the only legal start is the
        // document start, which BlockParser hands to opensDocument().
        return '';
    }

    public function tryStart(ParserState $state, int $containerOrdinal, bool $paragraphOpen): ?BlockStart
    {
        return null;
    }

    /**
     * Whether the input opens with a terminated front matter block, so that
     * BlockParser can open one before parsing the first line.
     *
     * The closing-fence lookahead only runs when the very first line is
     * already exactly a fence, so ordinary documents pay one string compare
     * for the whole parse.
     */
    public function opensDocument(ParserState $state): bool
    {
        if (0 !== $state->line) {
            return false;
        }

        $fence = $this->fenceAt($state->buffer->bytes, $state->offset, $state->lineContentEnd);

        return null !== $fence && $this->hasClosingFence($state, $fence);
    }

    public function tryContinue(ParserState $state, int $ordinal): ContinueResult
    {
        $tape = $state->tape;
        $bytes = $state->buffer->bytes;
        $contentEnd = $state->lineContentEnd;
        $fenceByte = $tape->flags($ordinal);

        if (0 === $fenceByte) {
            // The start-line call, when flags is still 0. opensDocument()
            // already proved the line is a fence.
            $fence = $this->fenceAt($bytes, $state->offset, $contentEnd) ?? self::YAML_FENCE;
            $tape->setFlags($ordinal, \ord($fence[0]));
            $tape->setEndOffset($ordinal, $state->lineEnd);
            $this->contentStart[$ordinal] = $state->lineEnd;
            $this->contentEnd[$ordinal] = $state->lineEnd;
            $state->advanceTo($contentEnd);

            return ContinueResult::Matched;
        }

        $fence = $this->fenceAt($bytes, $state->offset, $contentEnd);
        $closes = null !== $fence && $fenceByte === \ord($fence[0]);
        $tape->setEndOffset($ordinal, $state->lineEnd);
        // On the closing line the content stops before the fence; otherwise it
        // runs through this line's ending.
        $this->contentEnd[$ordinal] = $closes ? $state->offset : $state->lineEnd;
        $state->advanceTo($contentEnd);

        return $closes ? ContinueResult::Closed : ContinueResult::Matched;
    }

    public function close(ParserState $state, int $ordinal): void
    {
        if (!isset($this->contentStart[$ordinal])) {
            return;
        }

        $state->tape->setPayload($ordinal, $this->contentStart[$ordinal] . ':' . $this->contentEnd[$ordinal]);

        unset($this->contentStart[$ordinal], $this->contentEnd[$ordinal]);
    }

    /**
     * The fence a line carries, or null when the line is not exactly one.
     * Trailing spaces and tabs are ignored; nothing else may follow.
     */
    private function fenceAt(string $bytes, int $start, int $end): ?string
    {
        while ($end > $start && (' ' === $bytes[$end - 1] || "\t" === $bytes[$end - 1])) {
            --$end;
        }

        if (self::FENCE_LENGTH !== $end - $start) {
            return null;
        }

        $fence = substr($bytes, $start, self::FENCE_LENGTH);

        return self::YAML_FENCE === $fence || self::TOML_FENCE === $fence ? $fence : null;
    }

    /**
     * Whether any line after the first closes the given fence. Reads the line
     * table directly: the scan is bounded by the input's line count and runs
     * at most once per parse.
     */
    private function hasClosingFence(ParserState $state, string $fence): bool
    {
        $scanner = $state->scanner();
        $starts = $scanner->contentStarts();
        $ends = $scanner->contentEnds();
        $bytes = $state->buffer->bytes;
        $lineCount = \count($starts);

        for ($line = 1; $line < $lineCount; ++$line) {
            if ($fence === $this->fenceAt($bytes, $starts[$line], $ends[$line])) {
                return true;
            }
        }

        return false;
    }
}
