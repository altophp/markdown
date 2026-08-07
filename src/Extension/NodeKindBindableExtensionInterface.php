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

namespace Alto\Markdown\Extension;

/**
 * @internal built-in syntax binding compiled before parsing
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface NodeKindBindableExtensionInterface extends ExtensionInterface
{
    /**
     * @return list<string>
     */
    public function nodeKindNames(): array;

    /**
     * Return an immutable extension configured with the node kinds allocated
     * for the profile being compiled.
     */
    public function bindNodeKinds(NodeKindBindings $bindings): ExtensionInterface;
}
