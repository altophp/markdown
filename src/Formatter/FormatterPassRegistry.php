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

use Alto\Markdown\Formatter\Pass\AtxHeadingSpacingFormattingPass;
use Alto\Markdown\Formatter\Pass\BlankLineFormattingPass;
use Alto\Markdown\Formatter\Pass\BulletMarkerFormattingPass;
use Alto\Markdown\Formatter\Pass\FenceInfoSpacingFormattingPass;
use Alto\Markdown\Formatter\Pass\FenceMarkerFormattingPass;
use Alto\Markdown\Formatter\Pass\FinalNewlineFormattingPass;
use Alto\Markdown\Formatter\Pass\NoTrailingSpacesFormattingPass;
use Alto\Markdown\Formatter\Pass\OrderedListDelimiterFormattingPass;
use Alto\Markdown\Formatter\Pass\ReferenceDefinitionSpacingFormattingPass;
use Alto\Markdown\Formatter\Pass\TableDelimiterFormattingPass;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class FormatterPassRegistry
{
    /**
     * @var array<string, array{
     *     class: class-string<FormattingPass>,
     *     summary: string,
     *     level: FormattingLevel,
     *     styleField: ?string
     * }>
     */
    private const array PASSES = [
        'no-trailing-spaces' => [
            'class' => NoTrailingSpacesFormattingPass::class,
            'summary' => 'Remove trailing whitespace while preserving hard breaks.',
            'level' => FormattingLevel::Source,
            'styleField' => null,
        ],
        'atx-heading-spacing' => [
            'class' => AtxHeadingSpacingFormattingPass::class,
            'summary' => 'Normalize spacing after ATX heading markers.',
            'level' => FormattingLevel::Block,
            'styleField' => 'normalizeHeadingSpacing',
        ],
        'fence-marker' => [
            'class' => FenceMarkerFormattingPass::class,
            'summary' => 'Normalize fenced code block markers.',
            'level' => FormattingLevel::Block,
            'styleField' => 'fenceMarker',
        ],
        'fence-info-spacing' => [
            'class' => FenceInfoSpacingFormattingPass::class,
            'summary' => 'Remove whitespace before non-empty fence info strings.',
            'level' => FormattingLevel::Block,
            'styleField' => null,
        ],
        'bullet-marker' => [
            'class' => BulletMarkerFormattingPass::class,
            'summary' => 'Normalize unordered list markers.',
            'level' => FormattingLevel::Container,
            'styleField' => 'bulletMarker',
        ],
        'ordered-list-delimiter' => [
            'class' => OrderedListDelimiterFormattingPass::class,
            'summary' => 'Normalize ordered-list delimiters.',
            'level' => FormattingLevel::Container,
            'styleField' => 'orderedListDelimiter',
        ],
        'table-delimiter' => [
            'class' => TableDelimiterFormattingPass::class,
            'summary' => 'Normalize top-level GFM table delimiter rows.',
            'level' => FormattingLevel::Block,
            'styleField' => 'normalizeTableDelimiters',
        ],
        'reference-definition-spacing' => [
            'class' => ReferenceDefinitionSpacingFormattingPass::class,
            'summary' => 'Normalize single-line reference definition spacing.',
            'level' => FormattingLevel::Block,
            'styleField' => 'normalizeReferenceDefinitionSpacing',
        ],
        'blank-lines' => [
            'class' => BlankLineFormattingPass::class,
            'summary' => 'Normalize blank lines between top-level blocks.',
            'level' => FormattingLevel::Document,
            'styleField' => 'normalizeBlankLines',
        ],
        'final-newline' => [
            'class' => FinalNewlineFormattingPass::class,
            'summary' => 'Append the dominant line ending when missing.',
            'level' => FormattingLevel::Source,
            'styleField' => 'finalNewline',
        ],
    ];

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys(self::PASSES);
    }

    /**
     * @return list<FormatterPassMetadata>
     */
    public function metadata(): array
    {
        $metadata = [];

        foreach (self::PASSES as $id => $definition) {
            $metadata[] = $this->metadataFrom($id, $definition);
        }

        return $metadata;
    }

    public function metadataFor(string $id): ?FormatterPassMetadata
    {
        $definition = self::PASSES[$id] ?? null;

        return null === $definition ? null : $this->metadataFrom($id, $definition);
    }

    /**
     * @internal
     *
     * @return list<FormattingPass>
     */
    public function createAll(): array
    {
        return array_map(
            static fn (array $definition): FormattingPass => new $definition['class'](),
            array_values(self::PASSES),
        );
    }

    /**
     * @param array{
     *     class: class-string<FormattingPass>,
     *     summary: string,
     *     level: FormattingLevel,
     *     styleField: ?string
     * } $definition
     */
    private function metadataFrom(string $id, array $definition): FormatterPassMetadata
    {
        return new FormatterPassMetadata(
            $id,
            $definition['summary'],
            $definition['level'],
            $definition['styleField'],
        );
    }
}
