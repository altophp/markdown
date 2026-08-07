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
use Alto\Markdown\Extension\FrontMatter\FrontMatterExtension;
use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Extension\GitHub\GitHubAlertsExtension;
use Alto\Markdown\Render\MarkdownStyle;

/**
 * GitHub platform profile: GFM plus GitHub behavior outside the published
 * GFM spec. Alerts and front matter are github-only; later platform features
 * must remain separate from the GFM profile.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class GitHubProfile extends AbstractProfile
{
    public function __construct()
    {
        parent::__construct(
            'github',
            [new CoreExtension(), new GfmExtension(), new GitHubAlertsExtension(), new FrontMatterExtension()],
            style: MarkdownStyle::github(),
        );
    }
}
