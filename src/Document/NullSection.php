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

namespace Alto\Markdown\Document;

use Alto\Markdown\Builder\MarkdownFragment;
use Alto\Markdown\Exception\MissingSectionException;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Node\Section;
use Alto\Markdown\Source\SourceRange;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class NullSection implements Section
{
    public function __construct(private MarkdownDocument $document) {}

    public function id(): NodeId
    {
        return new NodeId(-1, -1);
    }

    public function kind(): NodeKind
    {
        return new NodeKind(0, 'missing-section');
    }

    public function range(): SourceRange
    {
        return new SourceRange(0, 0);
    }

    public function title(): string
    {
        return '';
    }

    public function exists(): bool
    {
        return false;
    }

    public function rename(string $title): self
    {
        throw new MissingSectionException('Cannot rename a missing section.');
    }

    public function remove(): MarkdownDocument
    {
        return $this->document;
    }

    public function append(MarkdownFragment|string $content): self
    {
        throw new MissingSectionException('Cannot append to a missing section.');
    }

    public function prepend(MarkdownFragment|string $content): self
    {
        throw new MissingSectionException('Cannot prepend to a missing section.');
    }

    public function replaceBody(MarkdownFragment|string $content): MarkdownDocument
    {
        return $this->document;
    }
}
