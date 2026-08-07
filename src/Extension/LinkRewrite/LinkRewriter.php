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

namespace Alto\Markdown\Extension\LinkRewrite;

use Alto\Markdown\Document\InlineLinkTarget;
use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Exception\InvalidMarkdownOperationException;
use Alto\Markdown\Exception\UnsupportedDocumentModelException;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Operation\InlineLinkSyntax;
use Alto\Markdown\Operation\SetInlineLinkOperation;
use Alto\Markdown\Parser\Inline\Href;
use Alto\Markdown\Parser\Inline\InlineKind;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LinkRewriter implements LinkDestinationRewriter
{
    /**
     * @param list<\Closure(LinkDestinationContext): string> $steps
     */
    private function __construct(private array $steps)
    {
    }

    /**
     * Prefix path-like destinations with one fixed URI. This is deliberately
     * not RFC 3986 document-relative resolution: both `/guide` and `guide`
     * become `{base}/guide`. Empty, fragment-only, query-only,
     * scheme-relative, and absolute destinations stay unchanged.
     */
    public static function baseUri(string $baseUri): self
    {
        InlineLinkSyntax::validateDestination($baseUri);
        $baseUri = rtrim($baseUri, '/');
        if ('' === $baseUri) {
            throw new InvalidExtensionException('Link rewrite base URI must not be empty.');
        }

        return new self([
            static function (LinkDestinationContext $context) use ($baseUri): string {
                $destination = $context->destination;
                if (
                    '' === $destination
                    || '#' === $destination[0]
                    || '?' === $destination[0]
                    || str_starts_with($destination, '//')
                    || 1 === preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/D', $destination)
                ) {
                    return $destination;
                }

                return $baseUri.'/'.ltrim($destination, '/');
            },
        ]);
    }

    /**
     * @param array<string, string> $destinations
     */
    public static function map(array $destinations): self
    {
        foreach ($destinations as $from => $to) {
            if (!\is_string($from) || !\is_string($to)) {
                throw new InvalidExtensionException('Link rewrite maps require string keys and string values.');
            }
            InlineLinkSyntax::validateDestination($to);
        }

        return new self([
            static fn (LinkDestinationContext $context): string => $destinations[$context->destination]
                ?? $context->destination,
        ]);
    }

    public static function pattern(string $pattern, string $replacement): self
    {
        if (false === @preg_match($pattern, '')) {
            throw new InvalidExtensionException(\sprintf('Invalid link rewrite pattern "%s".', $pattern));
        }

        return new self([
            static function (LinkDestinationContext $context) use ($pattern, $replacement): string {
                $rewritten = preg_replace($pattern, $replacement, $context->destination);
                if (null === $rewritten) {
                    throw new InvalidMarkdownOperationException('Link rewrite pattern failed: '.preg_last_error_msg().'.');
                }

                return $rewritten;
            },
        ]);
    }

    /**
     * @param callable(LinkDestinationContext): string $callback
     */
    public static function callback(callable $callback): self
    {
        $callback = \Closure::fromCallable($callback);

        return new self([
            static fn (LinkDestinationContext $context): string => $callback($context),
        ]);
    }

    public static function compose(self $first, self ...$rest): self
    {
        $steps = $first->steps;

        foreach ($rest as $rewriter) {
            array_push($steps, ...$rewriter->steps);
        }

        return new self($steps);
    }

    public function then(self $next): self
    {
        return self::compose($this, $next);
    }

    public function rewrite(LinkDestinationContext $context): string
    {
        $destination = $context->destination;

        foreach ($this->steps as $step) {
            $destination = $step($context->withDestination($destination));
            InlineLinkSyntax::validateDestination($destination);
        }

        return $destination;
    }

    /**
     * Explicitly rewrite inline Markdown link and image destinations.
     *
     * Reference-style links and images stay untouched because changing a
     * shared definition is a separate responsibility. Nested link/image
     * ranges stay untouched because their source patches overlap.
     */
    public function rewriteDocument(MarkdownDocument $document): LinkRewriteResult
    {
        $model = $document->model();
        if (!$model instanceof ParsedDocumentModel) {
            throw new UnsupportedDocumentModelException('Link rewriting requires ParsedDocumentModel.');
        }
        if (!$model->journal()->isEmpty()) {
            throw new InvalidMarkdownOperationException('Rewrite link destinations before making other document edits.');
        }

        $targets = $model->inlineLinkTargets();
        $overlaps = $this->overlappingTargets($targets);
        $edits = [];
        $unchanged = 0;
        $skippedReferences = 0;
        $skippedOverlaps = 0;

        foreach ($targets as $index => $target) {
            if (!$target->inlineSyntax) {
                ++$skippedReferences;

                continue;
            }
            if (isset($overlaps[$index])) {
                ++$skippedOverlaps;

                continue;
            }

            $destination = $this->rewrite(new LinkDestinationContext(
                $target->kind,
                Href::encode($target->destination),
                $target->range,
                $target->source,
            ));
            $destination = Href::encode($destination);

            if ($destination === $target->destination) {
                ++$unchanged;

                continue;
            }

            $edits[] = [$target, $destination];
        }

        foreach ($edits as [$target, $destination]) {
            $this->apply($model, $target, $destination);
        }

        return new LinkRewriteResult(
            \count($edits),
            $unchanged,
            $skippedReferences,
            $skippedOverlaps,
        );
    }

    /**
     * @param list<InlineLinkTarget> $targets
     *
     * @return array<int, true>
     */
    private function overlappingTargets(array $targets): array
    {
        $overlaps = [];
        $count = \count($targets);

        for ($left = 0; $left < $count; ++$left) {
            if (!$targets[$left]->inlineSyntax) {
                continue;
            }

            for ($right = $left + 1; $right < $count; ++$right) {
                if ($targets[$right]->range->startOffset >= $targets[$left]->range->endOffset) {
                    break;
                }
                if (!$targets[$right]->inlineSyntax) {
                    continue;
                }

                $overlaps[$left] = true;
                $overlaps[$right] = true;
            }
        }

        return $overlaps;
    }

    private function apply(ParsedDocumentModel $model, InlineLinkTarget $target, string $destination): void
    {
        $block = $model->currentNodeId($target->blockOrdinal);
        $range = $model->inlineMutationPatchRange($block, $target->inlineOrdinal, $target->kindId);
        $label = $model->inlineLinkLabelMarkdown($block, $target->inlineOrdinal, $target->kindId);
        $operation = new SetInlineLinkOperation(
            $block,
            $target->inlineOrdinal,
            $target->kindId,
            $destination,
            $target->title,
            null,
            InlineKind::IMAGE === $target->kindId ? 'rewrite image destination' : 'rewrite link destination',
            $range,
            InlineLinkSyntax::withLabel($label, $destination, $target->title),
        );
        $operation->apply($model);
        $model->journal()->record($operation, $range);
    }
}
