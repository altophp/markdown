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

namespace Alto\Markdown\Lint\Options;

use Alto\Markdown\Lint\LintRuleOptions;
use Alto\Markdown\Operation\SetCodeBlockLanguageOperation;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class RequireCodeBlockLanguageOptions implements LintRuleOptions
{
    public function __construct(
        public ?string $defaultLanguage = 'text',
    ) {
        if (null !== $defaultLanguage) {
            SetCodeBlockLanguageOperation::validateLanguage($defaultLanguage);
        }
    }
}
