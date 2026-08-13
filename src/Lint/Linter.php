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

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Lint\Engine\RuleEngine;
use Alto\Markdown\MarkdownDocument;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class Linter
{
    public function __construct(
        private LintConfig $config,
    ) {}

    public function lint(MarkdownDocument $document): LintReport
    {
        $registry = new RuleRegistry();
        $model = $document->model();
        $definitions = $model instanceof ParsedDocumentModel
            ? $model->compiledProfile()->lintRules
            : [];
        $rules = $registry->resolveConfigured($this->config, $definitions);

        return new RuleEngine(
            rules: $rules->builtIns,
            customRules: $rules->custom,
        )->run($model, $this->config);
    }
}
