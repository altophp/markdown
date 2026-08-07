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

namespace Alto\Markdown\Parser\Inline;

use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;

/**
 * Shared cursor over one block's joined inline content (InlineContent).
 * Constructs consume bytes by advancing the content cursor and append
 * nodes through the emit helpers; tape offsets are translated back to
 * original source bytes. Text runs accumulate lazily: emitters flush
 * pending text first, so adjacent literal bytes coalesce into one TEXT
 * node. Text runs never contain the joint "\n": the parser loop owns it.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
class InlineState implements InlineScanState
{
    private int $offset = 0;

    private int $textStart = 0;

    private int $lastChild = ParseTape::NONE;

    public function __construct(
        private readonly InlineContent $content,
        private readonly ParseTape $tape,
        private readonly ReferenceMap $referenceMap,
        private readonly int $root,
    ) {
    }

    public function content(): InlineContent
    {
        return $this->content;
    }

    public function tape(): ParseTape
    {
        return $this->tape;
    }

    public function referenceMap(): ReferenceMap
    {
        return $this->referenceMap;
    }

    /**
     * Content offset of the cursor (an offset into content()->text).
     */
    public function offset(): int
    {
        return $this->offset;
    }

    public function atEnd(): bool
    {
        return $this->offset >= \strlen($this->content->text);
    }

    /**
     * Seeds the sibling chain: the next appended node links after this
     * ordinal instead of extending the previous chain position. Link
     * wrapping re-anchors the chain onto the new LINK or IMAGE node.
     */
    public function continueAfter(int $ordinal): void
    {
        $this->lastChild = $ordinal;
    }

    public function remaining(): string
    {
        return substr($this->content->text, $this->offset);
    }

    /**
     * Moves the cursor forward without emitting: the skipped bytes stay
     * part of the pending text run.
     */
    public function advance(int $bytes): void
    {
        $this->offset = min($this->offset + $bytes, \strlen($this->content->text));
    }

    /**
     * Emits a node covering the content range [offset, $end) and moves
     * the cursor past it. Pending text is flushed first. Tape offsets are
     * source bytes. Returns the node's ordinal.
     */
    public function emit(int $kind, int $end, int $flags = 0, ?string $payload = null): int
    {
        $this->flushText();

        $ordinal = $this->append($kind, $this->offset, $end, $flags, $payload);
        $this->offset = $end;
        $this->textStart = $end;

        return $ordinal;
    }

    public function emitExtension(int $kind, int $end, InlineNode $node): void
    {
        $source = substr($this->content->text, $this->offset, $end - $this->offset);
        $ordinal = $this->emit($kind, $end, payload: $source);
        $this->tape->setExtensionInlineNode($ordinal, $node);
    }

    /**
     * Emits a bracket marker and returns its stable predecessor before link
     * resolution rewrites the sibling chain.
     *
     * @return array{int, int}
     */
    public function emitBracket(int $end): array
    {
        $this->flushText();
        $previous = $this->lastChild;
        $ordinal = $this->append(InlineKind::TEXT, $this->offset, $end);
        $this->offset = $end;
        $this->textStart = $end;

        return [$ordinal, $previous];
    }

    /**
     * Flushes the pending text run as a TEXT node. $trim strips that many
     * bytes from the run's end (break whitespace, the break backslash);
     * the stripped bytes never become text.
     */
    public function flushText(int $trim = 0): void
    {
        $end = max($this->textStart, $this->offset - $trim);

        if ($end > $this->textStart) {
            $this->append(InlineKind::TEXT, $this->textStart, $end);
        }

        $this->textStart = $this->offset;
    }

    /**
     * Moves the cursor forward, discarding the bytes entirely: they join
     * neither the pending text run nor any node (leading whitespace after
     * a break).
     */
    public function skip(int $bytes): void
    {
        $this->offset = min($this->offset + $bytes, \strlen($this->content->text));
        $this->textStart = $this->offset;
    }

    /**
     * Accounts for a node allocated outside append(), such as a link wrapper.
     */
    public function reserveNode(int $sourceOffset): void
    {
    }

    protected function append(int $kind, int $contentStart, int $contentEnd, int $flags = 0, ?string $payload = null): int
    {
        $ordinal = $this->tape->appendChild(
            $kind,
            $this->root,
            $this->lastChild,
            $this->content->sourceOffset($contentStart),
            $this->content->sourceOffset($contentEnd),
            0,
            $flags,
            $payload,
        );

        $this->lastChild = $ordinal;

        return $ordinal;
    }
}
