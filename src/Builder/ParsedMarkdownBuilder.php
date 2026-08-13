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

use Alto\Markdown\Document\ParsedMarkdownFactory;
use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Render\RenderOptions;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ParsedMarkdownBuilder implements MarkdownBuilder
{
    /**
     * @var list<string>
     */
    private array $blocks = [];

    public function __construct(private readonly ParsedMarkdownFactory $factory) {}

    public function heading(int $level, string $text): self
    {
        if ($level < 1 || $level > 6) {
            throw new InvalidMarkdownArgumentException('Heading level must be between 1 and 6.');
        }

        $this->blocks[] = str_repeat('#', $level) . ' ' . $this->text($text);

        return $this;
    }

    public function h1(string $text): self
    {
        return $this->heading(1, $text);
    }

    public function h2(string $text): self
    {
        return $this->heading(2, $text);
    }

    public function paragraph(string $text): self
    {
        $this->blocks[] = $this->text($text);

        return $this;
    }

    public function codeBlock(?string $language, string $code): self
    {
        $fence = $this->fence($code);
        $info = null === $language ? '' : $this->info($language);

        $this->blocks[] = $fence . $info . "\n" . rtrim($code, "\n") . "\n" . $fence;

        return $this;
    }

    public function unorderedList(iterable $items): self
    {
        $this->blocks[] = $this->list($items, static fn(int $index): string => '-');

        return $this;
    }

    public function orderedList(iterable $items): self
    {
        $this->blocks[] = $this->list($items, static fn(int $index): string => ($index + 1) . '.');

        return $this;
    }

    public function blockquote(string $text): self
    {
        $this->blocks[] = implode("\n", array_map(
            static fn(string $line): string => '' === $line ? '>' : '> ' . $line,
            explode("\n", $this->text($text)),
        ));

        return $this;
    }

    public function thematicBreak(): self
    {
        $this->blocks[] = '---';

        return $this;
    }

    public function document(): MarkdownDocument
    {
        return $this->factory->fromString($this->toMarkdown());
    }

    public function toMarkdown(?RenderOptions $options = null): string
    {
        if ([] === $this->blocks) {
            return '';
        }

        return implode("\n\n", $this->blocks) . "\n";
    }

    /**
     * @param iterable<string>      $items
     * @param callable(int): string $marker
     */
    private function list(iterable $items, callable $marker): string
    {
        $lines = [];
        $index = 0;

        foreach ($items as $item) {
            $prefix = $marker($index) . ' ';
            $indent = str_repeat(' ', \strlen($prefix));
            $itemLines = explode("\n", $this->text($item));
            $first = array_shift($itemLines) ?? '';
            $lines[] = $prefix . $first;

            foreach ($itemLines as $line) {
                $lines[] = '' === $line ? '' : $indent . $line;
            }

            ++$index;
        }

        return implode("\n", $lines);
    }

    private function text(string $text): string
    {
        return (string) preg_replace_callback(
            '/[!"#$%&\'()*+,\\.\/:;<=>?@\[\\\\\]\^_`{|}~-]/',
            static fn(array $match): string => '\\' . $match[0],
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
