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

namespace Alto\Markdown\Extension\GitHub;

use Alto\Markdown\Extension\AbstractExtension;
use Alto\Markdown\Profile\Feature;

/**
 * GitHub platform alerts. This is intentionally separate from GFM, whose
 * syntax is the published GFM spec and nothing platform-specific.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class GitHubAlertsExtension extends AbstractExtension
{
    public const string ALERT_KIND = 'github:alert';

    public function __construct()
    {
        parent::__construct('github', [Feature::GitHubAlerts], ['alert']);
    }
}
