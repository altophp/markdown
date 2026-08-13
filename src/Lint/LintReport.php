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

namespace Alto\Markdown\Lint;

/**
 * @implements \IteratorAggregate<int, LintProblem>
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class LintReport implements \Countable, \IteratorAggregate
{
    /**
     * @var list<LintProblem>
     */
    public array $problems;

    /**
     * @param list<LintProblem> $problems
     */
    public function __construct(array $problems)
    {
        usort(
            $problems,
            static fn(LintProblem $left, LintProblem $right): int => [
                $left->range->startOffset,
                $left->ruleId,
            ] <=> [
                $right->range->startOffset,
                $right->ruleId,
            ],
        );

        $this->problems = $problems;
    }

    public function isClean(): bool
    {
        return [] === $this->problems;
    }

    public function hasErrors(): bool
    {
        foreach ($this->problems as $problem) {
            if (LintSeverity::Error === $problem->severity) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return \count($this->problems);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->problems;
    }
}
