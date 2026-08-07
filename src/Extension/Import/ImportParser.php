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

namespace Alto\Markdown\Extension\Import;

use Alto\Markdown\Extension\Block\BlockContinueContext;
use Alto\Markdown\Extension\Block\BlockContinueResult;
use Alto\Markdown\Extension\Block\BlockParser;
use Alto\Markdown\Extension\Block\BlockStartContext;
use Alto\Markdown\Extension\Block\BlockStartResult;
use Alto\Markdown\Extension\Block\BlockState;
use Alto\Markdown\Extension\Resource\ResourceLineSelector;
use Alto\Markdown\Extension\Resource\ResourceOptionTokenizer;
use Alto\Markdown\Resource\ResourceRequest;
use Alto\Markdown\Resource\ResourceResolver;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ImportParser implements BlockParser
{
    private const int MAX_DIRECTIVE_BYTES = 4_096;

    private const int MAX_LINE_NUMBER = 1_000_000;

    private const int MAX_INDENT = 32;

    private const int MAX_LANGUAGE_BYTES = 64;

    /**
     * @var non-empty-array<string, true>
     */
    private const array OPTION_NAMES = [
        'lines' => true,
        'lang' => true,
        'indent' => true,
    ];

    public function __construct(private ResourceResolver $resolver)
    {
    }

    public function triggerBytes(): string
    {
        return '@';
    }

    public function tryStart(BlockStartContext $context): ?BlockStartResult
    {
        if ($context->paragraphOpen() || 0 !== $context->indentColumns()) {
            return null;
        }

        $first = $context->firstNonSpaceOffset();
        if ($first !== $context->lineStartOffset()) {
            return null;
        }

        $line = $context->slice($first, $context->lineContentEndOffset());
        if (
            \strlen($line) > self::MAX_DIRECTIVE_BYTES
            || !str_starts_with($line, '@import')
            || !isset($line[7])
            || (' ' !== $line[7] && "\t" !== $line[7])
            || 1 !== preg_match('/^@import[ \t]+"([^"\x00-\x1f]+)"(?:[ \t]+\{([^{}]*)\})?[ \t]*$/D', $line, $matches)
        ) {
            return null;
        }

        $options = $this->parseOptions($matches[2] ?? '');
        if (null === $options) {
            return null;
        }

        $resource = $this->resolver->resolve(new ResourceRequest(
            reference: $matches[1],
            purpose: 'import',
        ));
        $content = $resource->bytes;

        if (null !== $options['lineStart']) {
            $content = ResourceLineSelector::select(
                $content,
                $options['lineStart'],
                $options['lineEnd'] ?? $options['lineStart'],
            );
        }

        if ($options['indent'] > 0) {
            $content = $this->indent($content, $options['indent']);
        }

        return new BlockStartResult(
            startOffset: $first,
            advanceOffset: $context->lineContentEndOffset(),
            state: new BlockState([
                'content' => $content,
                'language' => $options['language'],
            ]),
        );
    }

    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        unset($context);

        return BlockContinueResult::notMatched();
    }

    /**
     * @return array{lineStart: int|null, lineEnd: int|null, language: string|null, indent: int}|null
     */
    private function parseOptions(string $source): ?array
    {
        $options = [
            'lineStart' => null,
            'lineEnd' => null,
            'language' => null,
            'indent' => 0,
        ];

        $values = ResourceOptionTokenizer::tokenize($source, self::OPTION_NAMES);
        if (null === $values) {
            return null;
        }

        foreach ($values as $name => $option) {
            if ('lines' === $name) {
                if (
                    $option->doubleQuoted
                    || 1 !== preg_match('/^([1-9][0-9]*)(?:-([1-9][0-9]*))?$/D', $option->value, $range)
                ) {
                    return null;
                }

                $start = (int) $range[1];
                $end = isset($range[2]) ? (int) $range[2] : $start;

                if ($start > self::MAX_LINE_NUMBER || $end > self::MAX_LINE_NUMBER || $end < $start) {
                    return null;
                }

                $options['lineStart'] = $start;
                $options['lineEnd'] = $end;

                continue;
            }

            if ('lang' === $name) {
                if (
                    \strlen($option->value) > self::MAX_LANGUAGE_BYTES
                    || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+-]*$/D', $option->value)
                ) {
                    return null;
                }

                $options['language'] = $option->value;

                continue;
            }

            if ($option->doubleQuoted || 1 !== preg_match('/^(?:0|[1-9][0-9]*)$/D', $option->value)) {
                return null;
            }

            $indent = (int) $option->value;
            if ($indent > self::MAX_INDENT) {
                return null;
            }
            $options['indent'] = $indent;
        }

        return $options;
    }

    private function indent(string $content, int $columns): string
    {
        if ('' === $content) {
            return '';
        }

        $padding = str_repeat(' ', $columns);
        $indented = $padding;
        $length = \strlen($content);

        for ($offset = 0; $offset < $length; ++$offset) {
            $byte = $content[$offset];
            $indented .= $byte;

            if ("\r" === $byte && $offset + 1 < $length && "\n" === $content[$offset + 1]) {
                $indented .= "\n";
                ++$offset;
            }

            if (("\r" === $byte || "\n" === $byte) && $offset + 1 < $length) {
                $indented .= $padding;
            }
        }

        return $indented;
    }
}
