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

namespace Alto\Markdown\Formatter;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Exception\InvalidFormatterResultException;
use Alto\Markdown\Extension\Formatter\FormatterContext;
use Alto\Markdown\Extension\Formatter\FormatterEdit;
use Alto\Markdown\Extension\Formatter\FormatterPassDefinition;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Render\MarkdownStyle;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Traversal\TapeDocumentTraversal;
use Alto\Markdown\Traversal\TraversalOptions;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ExtensionFormattingPass implements FormattingPass
{
    /**
     * @param array<string, FormatterPassDefinition> $definitions
     */
    public function __construct(private array $definitions) {}

    public function level(): FormattingLevel
    {
        return FormattingLevel::Document;
    }

    public function format(DocumentModel $model, MarkdownStyle $style): FormatResult
    {
        if ([] === $this->definitions) {
            return new FormatResult();
        }

        $includeInlines = false;

        foreach ($this->definitions as $definition) {
            if ($definition->includeInlines) {
                $includeInlines = true;

                break;
            }
        }

        $collector = new ExtensionFormattingContextCollector();
        new TapeDocumentTraversal()->traverse(
            $model,
            $collector,
            new TraversalOptions(includeInlines: $includeInlines),
        );
        $context = new FormatterContext(
            $model->source()->bytes,
            $collector->blocks(),
            $collector->inlines(),
            $style,
        );
        $operations = [];

        foreach ($this->definitions as $id => $definition) {
            foreach ($definition->create()->format($context) as $edit) {
                if (!$edit instanceof FormatterEdit) {
                    throw new InvalidFormatterResultException(\sprintf('Custom formatter pass "%s" must yield %s values.', $id, FormatterEdit::class));
                }

                $this->assertRange($id, $edit->range, \strlen($context->source()));

                if ($edit->replacement === $context->slice($edit->range)) {
                    continue;
                }

                $operations[] = new SourcePatchOperation(new SourcePatch(
                    $edit->range,
                    $edit->replacement,
                    $edit->range,
                    $edit->description,
                ));
            }
        }

        return new FormatResult($operations);
    }

    private function assertRange(string $id, SourceRange $range, int $sourceLength): void
    {
        if ($range->startOffset < 0 || $range->endOffset < $range->startOffset || $range->endOffset > $sourceLength) {
            throw new InvalidFormatterResultException(\sprintf('Custom formatter pass "%s" edit range %d..%d must stay within source length %d.', $id, $range->startOffset, $range->endOffset, $sourceLength));
        }
    }
}
