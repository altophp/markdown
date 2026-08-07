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

namespace Alto\Markdown\Extension\Attributes;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
abstract class AttributeSet
{
    /**
     * @param array<string, true|string> $left
     * @param array<string, true|string> $right
     *
     * @return array<string, true|string>
     */
    public static function merge(array $left, array $right): array
    {
        if (isset($left['class']) || isset($right['class'])) {
            $classes = [];

            foreach ([$left['class'] ?? '', $right['class'] ?? ''] as $value) {
                if (!\is_string($value)) {
                    continue;
                }

                $candidates = preg_split('/\s+/', trim($value), -1, \PREG_SPLIT_NO_EMPTY);
                foreach (false === $candidates ? [] : $candidates as $class) {
                    $classes[$class] = true;
                }
            }

            unset($left['class'], $right['class']);
            $left = ['class' => implode(' ', array_keys($classes))] + $left;
        }

        return array_replace($left, $right);
    }
}
