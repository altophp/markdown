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

namespace Alto\Markdown\Operation;

use Alto\Markdown\DocumentModel;
use Alto\Markdown\Source\SourceRange;
use Alto\Markdown\Traversal\BlockEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FenceInfoSpacingOperationFactory
{
    public function create(
        DocumentModel $model,
        BlockEvent $event,
        string $description,
    ): ?SourcePatchOperation {
        if ('fenced-code' !== $event->kind->name) {
            return null;
        }

        $bytes = $model->source()->bytes;
        $markerStart = $event->range->startOffset;
        $lineEnd = \min(
            $markerStart + strcspn($bytes, "\r\n", $markerStart),
            \strlen($bytes),
        );
        $marker = $bytes[$markerStart] ?? '';

        if ('`' !== $marker && '~' !== $marker) {
            return null;
        }

        $markerEnd = $markerStart;

        while ($markerEnd < $lineEnd && $marker === $bytes[$markerEnd]) {
            ++$markerEnd;
        }

        $infoStart = $markerEnd;

        while ($infoStart < $lineEnd && (' ' === $bytes[$infoStart] || "\t" === $bytes[$infoStart])) {
            ++$infoStart;
        }

        if ($markerEnd === $infoStart || $infoStart === $lineEnd) {
            return null;
        }

        $range = new SourceRange($markerEnd, $infoStart);

        return new SourcePatchOperation(new SourcePatch($range, '', $range, $description));
    }
}
