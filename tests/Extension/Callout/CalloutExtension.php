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

namespace Alto\Markdown\Tests\Extension\Callout;

use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\SyntaxExtensionInterface;

/**
 * The worked example's extension, written the way a package outside this
 * repository would have to write it.
 *
 * AbstractExtension is @internal, so both interfaces are implemented by hand.
 * SyntaxExtensionInterface is @internal too: without it the compiler never
 * asks for blockConstructs(), so there is no supported way to declare syntax.
 */
final class CalloutExtension implements ExtensionInterface, SyntaxExtensionInterface
{
    public function name(): string
    {
        return 'example';
    }

    public function nodeKindNames(): array
    {
        return ['callout'];
    }

    public function blockConstructs(): array
    {
        return [new CalloutParser()];
    }

    public function inlineConstructs(): array
    {
        return [];
    }
}
