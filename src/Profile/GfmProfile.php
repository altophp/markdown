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

namespace Alto\Markdown\Profile;

use Alto\Markdown\Extension\CommonMark\CoreExtension;
use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Render\MarkdownStyle;

/**
 * Published GitHub Flavored Markdown profile: CommonMark plus tables,
 * task list items, strikethrough, extended autolinks, and tagfilter.
 * This profile deliberately excludes GitHub platform behavior.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class GfmProfile extends AbstractProfile
{
    public function __construct()
    {
        parent::__construct('gfm', [new CoreExtension(), new GfmExtension()], style: MarkdownStyle::gfm());
    }
}
