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

/**
 * @author Simon André <smn.andre@gmail.com>
 */
enum Feature: string
{
    case CommonMark = 'commonmark';
    case Tables = 'tables';
    case TaskLists = 'task-lists';
    case Strikethrough = 'strikethrough';
    case ExtendedAutolinks = 'extended-autolinks';
    case TagFilter = 'tagfilter';
    case GitHubAlerts = 'github-alerts';
    case FrontMatter = 'front-matter';
}
