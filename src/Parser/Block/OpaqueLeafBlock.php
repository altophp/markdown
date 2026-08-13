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

namespace Alto\Markdown\Parser\Block;

/**
 * An open leaf whose member lines cannot start nested Markdown blocks.
 *
 * At document root, BlockParser can dispatch the next line directly to this
 * continuation instead of walking the general container and start phases.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
interface OpaqueLeafBlock extends BlockConstruct {}
