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

namespace Alto\Markdown\Extension\ContentSlicer;

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Extension\Document\DocumentTransformDefinition;
use Alto\Markdown\Extension\DocumentTransformExtensionInterface;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ContentSlicerExtension implements DocumentTransformExtensionInterface
{
    public function __construct(private int $minLevel = 2)
    {
        if ($minLevel < 1 || $minLevel > 6) {
            throw new InvalidExtensionException('Content slice minimum level must be between 1 and 6.');
        }
    }

    public function name(): string
    {
        return 'content-slicer';
    }

    public function documentTransforms(): iterable
    {
        yield new DocumentTransformDefinition(
            'sections',
            fn(): ContentSlicerTransform => new ContentSlicerTransform($this->minLevel),
        );
    }
}
