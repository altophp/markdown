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

namespace Alto\Markdown\Extension\SmartPunctuation;

use Alto\Markdown\Extension\Inline\InlineDefinition;
use Alto\Markdown\Extension\InlineExtensionInterface;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SmartPunctuationExtension implements InlineExtensionInterface
{
    private SmartPunctuationPolicy $policy;

    public function __construct(?SmartPunctuationPolicy $policy = null)
    {
        $this->policy = $policy ?? new SmartPunctuationPolicy();
    }

    public function name(): string
    {
        return 'smart-punctuation';
    }

    public function inlines(): iterable
    {
        $output = new SmartPunctuationOutput();

        yield new InlineDefinition(
            kind: 'ellipsis',
            parser: new SmartEllipsisParser(),
            html: $output,
            markdown: $output,
        );
        yield new InlineDefinition(
            kind: 'dash',
            parser: new SmartDashParser(),
            html: $output,
            markdown: $output,
        );
        yield new InlineDefinition(
            kind: 'double-quote',
            parser: SmartQuoteParser::double(
                $this->policy->doubleQuoteOpener,
                $this->policy->doubleQuoteCloser,
            ),
            html: $output,
            markdown: $output,
        );
        yield new InlineDefinition(
            kind: 'single-quote',
            parser: SmartQuoteParser::single(
                $this->policy->singleQuoteOpener,
                $this->policy->singleQuoteCloser,
            ),
            html: $output,
            markdown: $output,
        );
    }
}
