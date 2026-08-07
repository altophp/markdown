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

namespace Alto\Markdown\Extension\Document;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DocumentTransformDefinition
{
    private \Closure $factory;

    /**
     * @param callable(): DocumentTransform $factory
     */
    public function __construct(
        public string $name,
        callable $factory,
        public int $order = 0,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/D', $name)) {
            throw new InvalidExtensionException(\sprintf('Document transform name "%s" must start with a lowercase letter and contain only lowercase letters, digits, and hyphens.', $name));
        }

        $this->factory = $factory(...);
    }

    public function create(): DocumentTransform
    {
        $transform = ($this->factory)();

        if (!$transform instanceof DocumentTransform) {
            throw new InvalidExtensionException(\sprintf('Document transform factory "%s" must return %s.', $this->name, DocumentTransform::class));
        }

        return $transform;
    }
}
