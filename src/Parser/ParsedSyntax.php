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

use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Profile\CompiledProfile;
use Alto\Markdown\Source\LineEnding;
use Alto\Markdown\Source\SourceDocument;

/**
 * Immutable ownership boundary for one block parse.
 *
 * The mutable parser builders stay private. A document workspace receives
 * shallow clones whose arrays remain shared through PHP copy-on-write until an
 * edit mutates them. Read-only consumers never receive mutation methods.
 *
 * The SourceDocument value object is built on first source() call: direct
 * conversion and query-only consumers never pay for it.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ParsedSyntax
{
    private ?SourceDocument $source = null;

    public function __construct(
        private readonly SourceBuffer $buffer,
        private readonly ParseTape $tape,
        private readonly int $rootOrdinal,
        private readonly CompiledProfile $profile,
        private readonly ReferenceMap $referenceMap,
        private readonly LineEnding $dominantEol,
        private readonly ParseOptions $parseOptions,
    ) {}

    public function source(): SourceDocument
    {
        return $this->source ??= new SourceDocument($this->buffer->bytes, $this->dominantEol, $this->buffer->hasBom);
    }

    public function buffer(): SourceBuffer
    {
        return $this->buffer;
    }

    public function tape(): ReadOnlyParseTape
    {
        return $this->tape;
    }

    public function rootOrdinal(): int
    {
        return $this->rootOrdinal;
    }

    public function profile(): CompiledProfile
    {
        return $this->profile;
    }

    public function parseOptions(): ParseOptions
    {
        return $this->parseOptions;
    }

    public function newWorkspaceTape(): ParseTape
    {
        ++Instrumentation::$workspaceTapeClones;

        return clone $this->tape;
    }

    public function newReferenceMap(): ReferenceMap
    {
        ++Instrumentation::$referenceMapClones;

        return clone $this->referenceMap;
    }
}
