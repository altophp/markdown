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

namespace Alto\Markdown\Node\Kind;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
interface NodeKindRegistry
{
    public function core(string $name): NodeKind;

    public function reserve(string $extensionName, string $localName): NodeKind;

    public function find(string $name): ?NodeKind;

    public function get(int $id): NodeKind;
}
