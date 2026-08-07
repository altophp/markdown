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

namespace Alto\Markdown\Tests\Conformance;

/**
 * Renders Markdown to HTML for conformance comparison only.
 *
 * This lives under tests/ on purpose: HTML rendering is a comparison harness
 * for the spec suite, not a shipped feature of the engine.
 */
interface HtmlRenderer
{
    public function render(string $markdown): string;
}
