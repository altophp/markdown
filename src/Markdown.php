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

namespace Alto\Markdown;

use Alto\Markdown\Document\ParsedMarkdownFactory;
use Alto\Markdown\Profile\CommonMarkProfile;
use Alto\Markdown\Profile\GfmProfile;
use Alto\Markdown\Profile\GitHubProfile;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class Markdown
{
    public static function commonmark(): MarkdownFactory
    {
        return new ParsedMarkdownFactory(new CommonMarkProfile());
    }

    public static function gfm(): MarkdownFactory
    {
        return new ParsedMarkdownFactory(new GfmProfile());
    }

    public static function github(): MarkdownFactory
    {
        return new ParsedMarkdownFactory(new GitHubProfile());
    }

    private function __construct() {}
}
