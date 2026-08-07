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

namespace Alto\Markdown\Extension\Lint;

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Source\SourceRange;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LintContext
{
    /**
     * @param list<LintBlock>  $blocks
     * @param list<LintInline> $inlines
     *
     * @internal Alto creates lint contexts for registered rules
     */
    public function __construct(
        private string $source,
        private array $blocks,
        private array $inlines,
    ) {
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return list<LintBlock>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /**
     * @return list<LintInline>
     */
    public function inlines(): array
    {
        return $this->inlines;
    }

    public function slice(SourceRange $range): string
    {
        $this->assertRange($range);

        return substr($this->source, $range->startOffset, $range->endOffset - $range->startOffset);
    }

    private function assertRange(SourceRange $range): void
    {
        if ($range->startOffset < 0 || $range->endOffset < $range->startOffset || $range->endOffset > \strlen($this->source)) {
            throw new InvalidExtensionException(\sprintf('Lint source range %d..%d must stay within source length %d.', $range->startOffset, $range->endOffset, \strlen($this->source)));
        }
    }
}
