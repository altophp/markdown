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

namespace Alto\Markdown\Extension\Source;

use Alto\Markdown\Extension\Block\HtmlBlockOutputContext;
use Alto\Markdown\Extension\Block\HtmlBlockRenderer;
use Alto\Markdown\Extension\Block\MarkdownBlockOutputContext;
use Alto\Markdown\Extension\Block\MarkdownBlockPrinter;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SourceOutput implements HtmlBlockRenderer, MarkdownBlockPrinter
{
    public function render(HtmlBlockOutputContext $context, string $children): string
    {
        unset($children);

        $title = $context->state()->value('title');
        $language = $context->state()->value('language');
        $numbers = $context->state()->value('numbers');
        $highlights = $context->state()->value('highlights');
        $class = \is_string($language)
            ? ' class="language-'.$context->escapeAttribute($language).'"'
            : '';

        $html = "<div class=\"source-block\">\n";
        if (\is_string($title)) {
            $html .= '<div class="source-title">'.$context->escapeText($title)."</div>\n";
        }
        $html .= '<div class="source-path">'
            .$context->escapeText($context->state()->string('path'))
            ."</div>\n<pre><code".$class.'>';

        $content = $context->state()->string('content');
        if (true === $numbers || \is_string($highlights)) {
            $html .= $this->renderLines(
                $context,
                $content,
                true === $numbers,
                \is_string($highlights) ? $highlights : null,
                $context->state()->int('start-line'),
            );
        } else {
            $html .= $context->escapeText($content);
        }

        return $html."</code></pre>\n</div>\n";
    }

    public function print(MarkdownBlockOutputContext $context, string $children): string
    {
        unset($children);

        return $context->source();
    }

    private function renderLines(
        HtmlBlockOutputContext $context,
        string $content,
        bool $numbers,
        ?string $highlights,
        int $lineNumber,
    ): string {
        $html = '';
        $length = \strlen($content);
        $lineStart = 0;
        $rangeOffset = 0;
        $range = null;

        if (null !== $highlights && '' !== $highlights) {
            $range = self::rangeAt($highlights, 0);
        }

        for ($offset = 0; $offset < $length; ++$offset) {
            $byte = $content[$offset];
            if ("\r" !== $byte && "\n" !== $byte) {
                continue;
            }

            $separator = $byte;
            if ("\r" === $byte && $offset + 1 < $length && "\n" === $content[$offset + 1]) {
                $separator .= "\n";
                ++$offset;
            }

            [$lineHtml, $range, $rangeOffset] = $this->renderLine(
                $context,
                substr($content, $lineStart, $offset - $lineStart - \strlen($separator) + 1),
                $lineNumber,
                $numbers,
                $highlights,
                $range,
                $rangeOffset,
            );
            $html .= $lineHtml.$separator;
            $lineStart = $offset + 1;
            ++$lineNumber;
        }

        if ($lineStart < $length) {
            [$lineHtml] = $this->renderLine(
                $context,
                substr($content, $lineStart),
                $lineNumber,
                $numbers,
                $highlights,
                $range,
                $rangeOffset,
            );
            $html .= $lineHtml;
        }

        return $html;
    }

    /**
     * @param array{int, int}|null $range
     *
     * @return array{string, array{int, int}|null, int}
     */
    private function renderLine(
        HtmlBlockOutputContext $context,
        string $line,
        int $lineNumber,
        bool $numbers,
        ?string $highlights,
        ?array $range,
        int $rangeOffset,
    ): array {
        while (null !== $range && $lineNumber > $range[1]) {
            $rangeOffset += 8;
            $range = null !== $highlights && $rangeOffset < \strlen($highlights)
                ? self::rangeAt($highlights, $rangeOffset)
                : null;
        }

        $highlighted = null !== $range && $lineNumber >= $range[0];
        $html = '<span class="line'.($highlighted ? ' highlighted' : '').'">';

        if ($numbers) {
            $html .= '<span class="line-number" data-line="'.$lineNumber.'" aria-hidden="true">'
                .$lineNumber
                .'</span>';
        }

        $html .= $context->escapeText($line).'</span>';

        return [$html, $range, $rangeOffset];
    }

    /**
     * @return array{int, int}
     */
    private static function rangeAt(string $compiled, int $offset): array
    {
        return [
            self::unsignedIntAt($compiled, $offset),
            self::unsignedIntAt($compiled, $offset + 4),
        ];
    }

    private static function unsignedIntAt(string $compiled, int $offset): int
    {
        return (\ord($compiled[$offset]) << 24)
            | (\ord($compiled[$offset + 1]) << 16)
            | (\ord($compiled[$offset + 2]) << 8)
            | \ord($compiled[$offset + 3]);
    }
}
