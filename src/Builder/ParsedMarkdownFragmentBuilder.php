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

namespace Alto\Markdown\Builder;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ParsedMarkdownFragmentBuilder implements MarkdownFragmentBuilder
{
    /**
     * @var list<string>
     */
    private array $blocks = [];

    public function paragraph(string $text): self
    {
        $this->blocks[] = $this->text($text);

        return $this;
    }

    public function codeBlock(?string $language, string $code): self
    {
        $fence = $this->fence($code);
        $info = null === $language ? '' : $this->info($language);
        $this->blocks[] = $fence.$info."\n".rtrim($code, "\n")."\n".$fence;

        return $this;
    }

    public function raw(string $markdown): self
    {
        $this->blocks[] = rtrim($markdown, "\n");

        return $this;
    }

    public function toFragment(): MarkdownFragment
    {
        if ([] === $this->blocks) {
            return new ParsedMarkdownFragment('');
        }

        $markdown = implode("\n\n", $this->blocks)."\n";

        return new ParsedMarkdownFragment($markdown);
    }

    private function text(string $text): string
    {
        return (string) preg_replace_callback(
            '/[!"#$%&\'()*+,\\.\/:;<=>?@\[\\\\\]\^_`{|}~-]/',
            static fn (array $match): string => '\\'.$match[0],
            $text,
        );
    }

    private function fence(string $code): string
    {
        preg_match_all('/`+/', $code, $matches);
        $max = 0;

        foreach ($matches[0] as $run) {
            $max = max($max, \strlen($run));
        }

        return str_repeat('`', max(3, $max + 1));
    }

    private function info(string $language): string
    {
        return str_replace(['\\', '`', "\n"], ['\\\\', '\\`', ' '], $language);
    }
}
