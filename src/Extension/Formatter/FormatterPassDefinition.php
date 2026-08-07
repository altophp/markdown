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

namespace Alto\Markdown\Extension\Formatter;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class FormatterPassDefinition
{
    private \Closure $factory;

    /**
     * @param callable(): FormatterPass $factory
     */
    public function __construct(
        public string $name,
        public string $summary,
        callable $factory,
        public int $order = 0,
        public bool $includeInlines = false,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            throw new InvalidExtensionException(\sprintf('Formatter pass name "%s" must start with a lowercase letter and contain only lowercase letters, digits, and hyphens.', $name));
        }

        if ('' === trim($summary)) {
            throw new InvalidExtensionException('Formatter pass summary must not be empty.');
        }

        $this->factory = $factory(...);
    }

    public function create(): FormatterPass
    {
        $pass = ($this->factory)();

        if (!$pass instanceof FormatterPass) {
            throw new InvalidExtensionException(\sprintf('Formatter pass factory "%s" must return %s.', $this->name, FormatterPass::class));
        }

        return $pass;
    }
}
