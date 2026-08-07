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

namespace Alto\Markdown\Extension\ExternalLink;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
enum ExternalLinkScope
{
    case None;
    case All;
    case Internal;
    case External;

    public function applies(bool $external): bool
    {
        return match ($this) {
            self::None => false,
            self::All => true,
            self::Internal => !$external,
            self::External => $external,
        };
    }
}
