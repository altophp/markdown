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

namespace Alto\Markdown\Parser;

use Alto\Markdown\Exception\SourceSizeLimitException;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Profile\CompiledProfile;

/**
 * Stateless entry point for block syntax parsing.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class SyntaxParser
{
    public function __construct(private CompiledProfile $profile) {}

    public function parse(string $source, ?ParseOptions $options = null): ParsedSyntax
    {
        return $this->parseSource($source, $options, true, null);
    }

    public function parseFragment(string $source, ?ParseOptions $options = null): ParsedSyntax
    {
        return $this->parseSource($source, $options, false, null);
    }

    public function parseFragmentWithoutBlockKind(string $source, ParseOptions $options, int $excludedKind): ParsedSyntax
    {
        return $this->parseSource($source, $options, false, $excludedKind);
    }

    private function parseSource(
        string $source,
        ?ParseOptions $options,
        bool $allowFrontMatter,
        ?int $excludedKind,
    ): ParsedSyntax {
        $options ??= new ParseOptions();
        $sourceBytes = \strlen($source);

        if (0 !== $options->maxSourceBytes && $sourceBytes > $options->maxSourceBytes) {
            throw new SourceSizeLimitException($options->maxSourceBytes, $sourceBytes);
        }

        ++Instrumentation::$syntaxParses;

        if (Instrumentation::$timing) {
            Instrumentation::enter('block-scan');
        }

        $buffer = new SourceBuffer($source);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);

        if (Instrumentation::$timing) {
            Instrumentation::leave('block-scan');
            Instrumentation::enter('block-setup');
        }

        $tape = new ParseTape();
        $state = new ParserState($buffer, $scanner, $map, $tape);
        $blockParser = new BlockParser($this->profile, $excludedKind);

        if (Instrumentation::$timing) {
            Instrumentation::leave('block-setup');
        }

        $root = $blockParser->parse(
            $state,
            $options->maxNestingDepth,
            $options->maxBlockCount,
            $options->maxReferenceCount,
            $allowFrontMatter,
        );

        return new ParsedSyntax(
            $buffer,
            $tape,
            $root,
            $this->profile,
            $blockParser->referenceMap(),
            $scanner->dominantEol(),
            $options,
        );
    }
}
