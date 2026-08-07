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
use Alto\Markdown\Render\MarkdownStyle;

/**
 * Strict CommonMark profile: core block and inline syntax only.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class CommonMarkProfile extends AbstractProfile
{
    public function __construct()
    {
        parent::__construct('commonmark', [new CoreExtension()], style: MarkdownStyle::commonmark());
    }
}
