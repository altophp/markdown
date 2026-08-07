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

use Alto\Markdown\Profile\Feature;

/**
 * Built-in feature metadata. Custom capabilities use their contribution
 * interfaces instead of extending the closed Feature enum.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface FeatureExtensionInterface extends ExtensionInterface
{
    /**
     * @return iterable<Feature>
     */
    public function features(): iterable;
}
