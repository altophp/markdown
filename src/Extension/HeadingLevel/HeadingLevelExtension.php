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

namespace Alto\Markdown\Extension\HeadingLevel;

use Alto\Markdown\Extension\Document\DocumentTransformDefinition;
use Alto\Markdown\Extension\DocumentTransformExtensionInterface;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HeadingLevelExtension implements DocumentTransformExtensionInterface
{
    public function __construct(private HeadingLevelPolicy $policy)
    {
    }

    public function name(): string
    {
        return 'heading-level';
    }

    public function documentTransforms(): iterable
    {
        yield new DocumentTransformDefinition(
            'levels',
            fn (): HeadingLevelTransform => new HeadingLevelTransform($this->policy),
            -100,
        );
    }
}
