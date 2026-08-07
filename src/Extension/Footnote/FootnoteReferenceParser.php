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

namespace Alto\Markdown\Extension\Footnote;

use Alto\Markdown\Extension\Inline\InlineNode;
use Alto\Markdown\Parser\Inline\ContentScannedInlineConstruct;
use Alto\Markdown\Parser\Inline\InlineScanState;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FootnoteReferenceParser implements ContentScannedInlineConstruct
{
    public function __construct(private int $kind)
    {
    }

    public function triggerBytes(): string
    {
        return '[';
    }

    public function contentScanTriggers(): array
    {
        return ['[^'];
    }

    public function nextCandidate(string $text, int $from): int
    {
        $candidate = strpos($text, '[^', $from);

        return false === $candidate ? -1 : $candidate;
    }

    public function tryParse(InlineScanState $state): bool
    {
        $text = $state->content()->text;
        $start = $state->offset();

        if (!str_starts_with(substr($text, $start), '[^')) {
            return false;
        }

        $close = strpos($text, ']', $start + 2);
        if (false === $close) {
            return false;
        }

        $label = substr($text, $start + 2, $close - $start - 2);
        if ('' === $label
            || \strlen($label) > 128
            || \strlen($label) !== strcspn($label, " \t\r\n\f\v^]")
        ) {
            return false;
        }

        $source = substr($text, $start, $close - $start + 1);
        $state->emitExtension(
            $this->kind,
            $close + 1,
            new InlineNode($source, ['label' => $label]),
        );

        return true;
    }
}
