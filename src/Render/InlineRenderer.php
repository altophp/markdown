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

namespace Alto\Markdown\Render;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Exception\RenderException;
use Alto\Markdown\Extension\Inline\MarkdownInlineOutputContext;
use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\ParseTape;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class InlineRenderer
{
    public function __construct(private readonly MarkdownStyle $style) {}

    public function renderBlock(ParsedDocumentModel $model, int $blockOrdinal): string
    {
        return $this->renderChildren($model, $blockOrdinal, 0);
    }

    private function renderChildren(ParsedDocumentModel $model, int $blockOrdinal, int $parent): string
    {
        $markdown = '';
        $node = $model->inlineFirstChildOrdinal($blockOrdinal, $parent);

        while (ParseTape::NONE !== $node) {
            $markdown .= $this->renderNode($model, $blockOrdinal, $node);
            $node = $model->inlineNextSiblingOrdinal($blockOrdinal, $node);
        }

        return $markdown;
    }

    private function renderNode(ParsedDocumentModel $model, int $blockOrdinal, int $node): string
    {
        return match ($model->inlineKindId($blockOrdinal, $node)) {
            InlineKind::TEXT => $this->escapeText($model->inlineLiteral($blockOrdinal, $node)),
            InlineKind::SOFT_BREAK => "\n",
            InlineKind::HARD_BREAK => "\\\n",
            InlineKind::CODE_SPAN => $this->codeSpan($model->inlineLiteral($blockOrdinal, $node)),
            InlineKind::EMPHASIS => '*' . $this->renderChildren($model, $blockOrdinal, $node) . '*',
            InlineKind::STRONG => '**' . $this->renderChildren($model, $blockOrdinal, $node) . '**',
            InlineKind::LINK => $this->link($model, $blockOrdinal, $node),
            InlineKind::IMAGE => $this->image($model, $blockOrdinal, $node),
            InlineKind::AUTOLINK => '<' . $model->inlineLiteral($blockOrdinal, $node) . '>',
            InlineKind::HTML_INLINE => $model->inlineLiteral($blockOrdinal, $node),
            InlineKind::STRIKETHROUGH => '~~' . $this->renderChildren($model, $blockOrdinal, $node) . '~~',
            default => $this->custom($model, $blockOrdinal, $node),
        };
    }

    private function custom(ParsedDocumentModel $model, int $blockOrdinal, int $node): string
    {
        $kind = $model->inlineKindId($blockOrdinal, $node);
        $printer = $model->compiledProfile()->markdownInlinePrinters[$kind]
            ?? throw new RenderException(\sprintf('No Markdown renderer for custom inline kind %d.', $kind));

        return $printer->print(new MarkdownInlineOutputContext(
            $model->inlineExtensionNode($blockOrdinal, $node),
            $model->inlineRange($blockOrdinal, $node),
            $model->inlineLiteral($blockOrdinal, $node),
            $this->style,
        ));
    }

    private function link(ParsedDocumentModel $model, int $blockOrdinal, int $node): string
    {
        [$destination, $title] = $model->inlinePayloadParts($blockOrdinal, $node);

        return '[' . $this->renderChildren($model, $blockOrdinal, $node) . ']('
            . $this->destination($destination)
            . $this->title($title)
            . ')';
    }

    private function image(ParsedDocumentModel $model, int $blockOrdinal, int $node): string
    {
        [$destination, $title] = $model->inlinePayloadParts($blockOrdinal, $node);
        $altText = $model->inlineImageAltTextOverride($blockOrdinal, $node);

        return '![' . (null === $altText ? $this->renderChildren($model, $blockOrdinal, $node) : $this->escapeText($altText)) . ']('
            . $this->destination($destination)
            . $this->title($title)
            . ')';
    }

    private function codeSpan(string $code): string
    {
        preg_match_all('/`+/', $code, $matches);
        $max = 0;

        foreach ($matches[0] as $run) {
            $max = max($max, \strlen($run));
        }

        $ticks = str_repeat('`', max(1, $max + 1));

        if ('' === $code) {
            return '``';
        }

        if (str_starts_with($code, '`')
            || str_ends_with($code, '`')
            || (str_starts_with($code, ' ') && str_ends_with($code, ' ') && '' !== trim($code, ' '))
        ) {
            return $ticks . ' ' . $code . ' ' . $ticks;
        }

        return $ticks . $code . $ticks;
    }

    private function destination(string $destination): string
    {
        return str_replace(['\\', ')'], ['\\\\', '\\)'], $destination);
    }

    private function title(string $title): string
    {
        if ('' === $title) {
            return '';
        }

        return ' "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $title) . '"';
    }

    private function escapeText(string $text): string
    {
        return (string) preg_replace_callback(
            '/[!"#$%&\'()*+,\\.\/:;<=>?@\[\\\\\]\^_`{|}~-]/',
            static fn(array $match): string => '\\' . $match[0],
            $text,
        );
    }
}
