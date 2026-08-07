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

namespace Alto\Markdown\Document\Handle;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Extension\FrontMatter\FrontMatterDecoder;
use Alto\Markdown\Node\FrontMatter;
use Alto\Markdown\Operation\ReplaceFrontMatterContentOperation;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FrontMatterHandle extends BlockNodeHandle implements FrontMatter
{
    public function text(): string
    {
        $this->assertExists();

        return $this->model->frontMatterText($this->id()->ordinal);
    }

    public function content(): string
    {
        $this->assertExists();

        return $this->model->frontMatterContent($this->id()->ordinal);
    }

    public function fence(): string
    {
        $this->assertExists();

        return substr($this->model->source()->bytes, $this->range()->startOffset, 3);
    }

    public function decode(FrontMatterDecoder $decoder): mixed
    {
        $this->assertExists();

        return $decoder->decode($this->content(), $this->fence());
    }

    public function replaceContent(string $content): self
    {
        $this->assertExists();
        $content = $this->normalizeContent($content);

        if ($content === $this->content()) {
            return $this;
        }

        $range = $this->model->frontMatterContentRange($this->id());
        $operation = new ReplaceFrontMatterContentOperation($this->id(), $content, $range);
        $operation->apply($this->model);
        $this->model->journal()->record($operation, $range);

        return new self($this->model, $this->model->currentNodeId($this->id()->ordinal));
    }

    private function normalizeContent(string $content): string
    {
        $eol = $this->model->frontMatterLineEnding($this->id()->ordinal)->value;
        $content = (string) preg_replace("/\r\n|\r|\n/", $eol, $content);

        if ('' !== $content && !str_ends_with($content, $eol)) {
            $content .= $eol;
        }

        foreach (explode($eol, $content) as $line) {
            if ($this->fence() === rtrim($line, " \t")) {
                throw new InvalidMarkdownArgumentException('Front matter content must not contain a line that closes its fence.');
            }
        }

        return $content;
    }
}
