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

namespace Alto\Markdown\Extension\Source;

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
final readonly class SourceParser implements BlockParser
{
    private const int MAX_DIRECTIVE_BYTES = 4_096;

    private const int MAX_LINE_NUMBER = 1_000_000;

    private const int MAX_LANGUAGE_BYTES = 64;

    private const int MAX_TITLE_BYTES = 256;

    private const int MAX_HIGHLIGHT_BYTES = 512;

    private const int MAX_HIGHLIGHT_RANGES = 64;

    /**
     * @var non-empty-array<string, true>
     */
    private const array OPTION_NAMES = [
        'lines' => true,
        'lang' => true,
        'title' => true,
        'numbers' => true,
        'highlight' => true,
    ];

    /**
     * @var array<string, string>
     */
    private const array LANGUAGES = [
        'php' => 'php',
        'js' => 'javascript',
        'mjs' => 'javascript',
        'cjs' => 'javascript',
        'ts' => 'typescript',
        'mts' => 'typescript',
        'cts' => 'typescript',
        'jsx' => 'jsx',
        'tsx' => 'tsx',
        'py' => 'python',
        'rb' => 'ruby',
        'go' => 'go',
        'rs' => 'rust',
        'java' => 'java',
        'c' => 'c',
        'h' => 'c',
        'cc' => 'cpp',
        'cpp' => 'cpp',
        'cxx' => 'cpp',
        'hpp' => 'cpp',
        'cs' => 'csharp',
        'swift' => 'swift',
        'kt' => 'kotlin',
        'kts' => 'kotlin',
        'scala' => 'scala',
        'r' => 'r',
        'sh' => 'bash',
        'bash' => 'bash',
        'zsh' => 'bash',
        'fish' => 'bash',
        'ps1' => 'powershell',
        'sql' => 'sql',
        'html' => 'html',
        'htm' => 'html',
        'xml' => 'xml',
        'svg' => 'xml',
        'css' => 'css',
        'scss' => 'scss',
        'sass' => 'scss',
        'less' => 'less',
        'json' => 'json',
        'jsonc' => 'json',
        'yaml' => 'yaml',
        'yml' => 'yaml',
        'toml' => 'toml',
        'ini' => 'ini',
        'md' => 'markdown',
        'markdown' => 'markdown',
        'rst' => 'restructuredtext',
        'tex' => 'latex',
        'cmake' => 'cmake',
        'nginx' => 'nginx',
        'conf' => 'apache',
        'vim' => 'vim',
        'lua' => 'lua',
        'pl' => 'perl',
        'pm' => 'perl',
        'asm' => 'assembly',
        's' => 'assembly',
        'diff' => 'diff',
        'patch' => 'diff',
        'csv' => 'csv',
        'txt' => 'text',
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
            || !str_starts_with($line, '@source')
            || !isset($line[7])
            || (' ' !== $line[7] && "\t" !== $line[7])
            || 1 !== preg_match('/^@source[ \t]+"([^"\x00-\x1f]+)"(?:[ \t]+\{([^{}]*)\})?[ \t]*$/D', $line, $matches)
        ) {
            return null;
        }

        $options = $this->parseOptions($matches[2] ?? '');
        if (null === $options) {
            return null;
        }

        $reference = $matches[1];
        $resource = $this->resolver->resolve(new ResourceRequest(
            reference: $reference,
            purpose: 'source',
        ));
        $content = $resource->bytes;

        if (null !== $options['lineStart']) {
            $content = ResourceLineSelector::select(
                $content,
                $options['lineStart'],
                $options['lineEnd'] ?? $options['lineStart'],
            );
        }

        return new BlockStartResult(
            startOffset: $first,
            advanceOffset: $context->lineContentEndOffset(),
            state: new BlockState([
                'content' => $content,
                'path' => $reference,
                'language' => $options['language'] ?? self::languageFor($reference),
                'title' => $options['title'],
                'numbers' => $options['numbers'],
                'start-line' => $options['lineStart'] ?? 1,
                'highlights' => $options['highlights'],
            ]),
        );
    }

    public function tryContinue(BlockContinueContext $context): BlockContinueResult
    {
        unset($context);

        return BlockContinueResult::notMatched();
    }

    /**
     * @return array{
     *     lineStart: int|null,
     *     lineEnd: int|null,
     *     language: string|null,
     *     title: string|null,
     *     numbers: bool,
     *     highlights: string|null
     * }|null
     */
    private function parseOptions(string $source): ?array
    {
        $options = [
            'lineStart' => null,
            'lineEnd' => null,
            'language' => null,
            'title' => null,
            'numbers' => false,
            'highlights' => null,
        ];

        $values = ResourceOptionTokenizer::tokenize($source, self::OPTION_NAMES);
        if (null === $values) {
            return null;
        }

        foreach ($values as $name => $option) {
            if ('lines' === $name) {
                $range = self::range($option->value, $option->doubleQuoted);
                if (null === $range) {
                    return null;
                }

                [$options['lineStart'], $options['lineEnd']] = $range;

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

            if ('title' === $name) {
                if (
                    !$option->doubleQuoted
                    || \strlen($option->value) > self::MAX_TITLE_BYTES
                    || 1 === preg_match('/[\x00-\x1f\x7f]/', $option->value)
                ) {
                    return null;
                }

                $options['title'] = $option->value;

                continue;
            }

            if ('numbers' === $name) {
                if ($option->doubleQuoted || ('true' !== $option->value && 'false' !== $option->value)) {
                    return null;
                }

                $options['numbers'] = 'true' === $option->value;

                continue;
            }

            if (\strlen($option->value) > self::MAX_HIGHLIGHT_BYTES) {
                return null;
            }

            $options['highlights'] = self::highlights($option->value);
            if (null === $options['highlights']) {
                return null;
            }
        }

        return $options;
    }

    /**
     * @return array{int, int}|null
     */
    private static function range(string $value, bool $doubleQuoted): ?array
    {
        if (
            $doubleQuoted
            || 1 !== preg_match('/^([1-9][0-9]*)(?:-([1-9][0-9]*))?$/D', $value, $matches)
        ) {
            return null;
        }

        $start = (int) $matches[1];
        $end = isset($matches[2]) ? (int) $matches[2] : $start;

        if ($start > self::MAX_LINE_NUMBER || $end > self::MAX_LINE_NUMBER || $end < $start) {
            return null;
        }

        return [$start, $end];
    }

    private static function highlights(string $value): ?string
    {
        $ranges = [];
        $rangeCount = 0;

        foreach (explode(',', $value) as $rangeSource) {
            $range = self::range(trim($rangeSource, " \t"), false);
            ++$rangeCount;
            if (null === $range || $rangeCount > self::MAX_HIGHLIGHT_RANGES) {
                return null;
            }

            $ranges = self::addRange($ranges, $range[0], $range[1]);
        }

        ksort($ranges, \SORT_NUMERIC);
        $compiled = '';
        $currentStart = null;
        $currentEnd = null;

        foreach ($ranges as $start => $end) {
            if (null === $currentStart || null === $currentEnd) {
                $currentStart = $start;
                $currentEnd = $end;

                continue;
            }

            if ($start <= $currentEnd + 1) {
                $currentEnd = max($currentEnd, $end);

                continue;
            }

            $compiled .= pack('NN', $currentStart, $currentEnd);
            $currentStart = $start;
            $currentEnd = $end;
        }

        if (null !== $currentStart && null !== $currentEnd) {
            $compiled .= pack('NN', $currentStart, $currentEnd);
        }

        return $compiled;
    }

    /**
     * @param array<int, int> $ranges
     *
     * @return array<int, int>
     */
    private static function addRange(array $ranges, int $start, int $end): array
    {
        $ranges[$start] = max($ranges[$start] ?? 0, $end);

        return $ranges;
    }

    private static function languageFor(string $reference): ?string
    {
        $path = str_replace('\\', '/', $reference);
        $separator = strrpos($path, '/');
        $basename = strtolower(false === $separator ? $path : substr($path, $separator + 1));

        if ('.htaccess' === $basename) {
            return 'apache';
        }
        if ('cmakelists.txt' === $basename) {
            return 'cmake';
        }
        if ('dockerfile' === $basename || str_starts_with($basename, 'dockerfile.')) {
            return 'dockerfile';
        }
        if ('makefile' === $basename || str_starts_with($basename, 'makefile.')) {
            return 'makefile';
        }

        $dot = strrpos($basename, '.');
        if (false === $dot || $dot === \strlen($basename) - 1) {
            return null;
        }

        return self::LANGUAGES[substr($basename, $dot + 1)] ?? null;
    }
}
