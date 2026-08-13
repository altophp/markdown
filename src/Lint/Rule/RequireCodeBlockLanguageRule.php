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

namespace Alto\Markdown\Lint\Rule;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Lint\BlockRule;
use Alto\Markdown\Lint\ConfigurableRule;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\LintRuleOptions;
use Alto\Markdown\Lint\Options\RequireCodeBlockLanguageOptions;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Traversal\BlockEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class RequireCodeBlockLanguageRule implements BlockRule, ConfigurableRule
{
    public function __construct(
        private RequireCodeBlockLanguageOptions $options = new RequireCodeBlockLanguageOptions(),
    ) {}

    public static function fromOptions(?LintRuleOptions $options): self
    {
        return new self(
            $options instanceof RequireCodeBlockLanguageOptions
                ? $options
                : new RequireCodeBlockLanguageOptions(),
        );
    }

    public function id(): string
    {
        return 'require-code-block-language';
    }

    public function enterBlock(BlockEvent $event, RuleContext $context): iterable
    {
        if ('fenced-code' !== $event->kind->name || !$event->model instanceof ParsedDocumentModel) {
            return;
        }

        if (null !== $event->model->codeBlockLanguage($event->id->ordinal)) {
            return;
        }

        $range = $this->emptyInfoRange($event);

        yield new LintProblem(
            $this->id(),
            'Fenced code block must declare a language.',
            $event->range,
            null === $range || null === $this->options->defaultLanguage
                ? null
                : new SourcePatchOperation(new SourcePatch(
                    $range,
                    $this->options->defaultLanguage,
                    $range,
                    'set code block language to ' . $this->options->defaultLanguage,
                )),
            severity: $context->config->severityFor($this->id()),
        );
    }

    private function emptyInfoRange(BlockEvent $event): ?SourceRange
    {
        $bytes = $event->model->source()->bytes;
        $lineEnd = $event->range->startOffset + strcspn($bytes, "\r\n", $event->range->startOffset);
        $offset = $event->range->startOffset;

        while ($offset < $lineEnd && (' ' === $bytes[$offset] || "\t" === $bytes[$offset])) {
            ++$offset;
        }

        if ($offset >= $lineEnd || ('`' !== $bytes[$offset] && '~' !== $bytes[$offset])) {
            return null;
        }

        $fence = $bytes[$offset];

        while ($offset < $lineEnd && $fence === $bytes[$offset]) {
            ++$offset;
        }

        return new SourceRange($offset, $offset);
    }
}
