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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Fixer\FixPlan;
use Alto\Markdown\MarkdownDocument;
use Alto\Markdown\Operation\Operation;
use Alto\Markdown\Render\MarkdownStyle;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class DocumentFormatRunner
{
    /**
     * @var list<FormattingPass>
     */
    private array $passes;

    /**
     * @param list<FormattingPass>|null $passes
     */
    public function __construct(
        private readonly MarkdownDocument $document,
        private MarkdownStyle $style,
        ?array $passes = null,
    ) {
        if (null !== $passes) {
            $this->passes = $passes;

            return;
        }

        $this->passes = new FormatterPassRegistry()->createAll();
        $model = $this->document->model();

        if ($model instanceof ParsedDocumentModel && [] !== $model->compiledProfile()->formatterPasses) {
            $this->passes[] = new ExtensionFormattingPass($model->compiledProfile()->formatterPasses);
        }
    }

    public function style(MarkdownStyle $style): self
    {
        $this->style = $style;

        return $this;
    }

    public function apply(): MarkdownDocument
    {
        new FixPlan($this->operations())->applyTo($this->document->model());

        return $this->document;
    }

    public function currentStyle(): MarkdownStyle
    {
        return $this->style;
    }

    /**
     * @return list<Operation>
     */
    private function operations(): array
    {
        $operations = [];

        foreach ($this->passes as $pass) {
            $result = $pass->format($this->document->model(), $this->style);

            if ($result->isEmpty()) {
                continue;
            }

            array_push($operations, ...$result->operations);
        }

        return $operations;
    }
}
