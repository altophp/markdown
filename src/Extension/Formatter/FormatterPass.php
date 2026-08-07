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

namespace Alto\Markdown\Extension\Formatter;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface FormatterPass
{
    /**
     * @return iterable<FormatterEdit>
     */
    public function format(FormatterContext $context): iterable;
}
