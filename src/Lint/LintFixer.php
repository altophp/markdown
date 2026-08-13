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

namespace Alto\Markdown\Lint;

use Alto\Markdown\Fixer\FixPlan;
use Alto\Markdown\Fixer\FixResult;
use Alto\Markdown\MarkdownDocument;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LintFixer
{
    public function __construct(
        private LintConfig $config,
    ) {}

    public function fix(MarkdownDocument $document): MarkdownDocument
    {
        $results = [];

        foreach ((new Linter($this->config))->lint($document) as $problem) {
            if (null === $problem->fix) {
                continue;
            }

            $results[] = new FixResult([$problem->fix]);
        }

        FixPlan::fromResults($results)->applyTo($document->model());

        return $document;
    }
}
