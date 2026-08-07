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

namespace Alto\Markdown\Lint\Rule;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Lint\BlockRule;
use Alto\Markdown\Lint\LintProblem;
use Alto\Markdown\Lint\RuleContext;
use Alto\Markdown\Traversal\BlockEvent;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ConsistentTableColumnsRule implements BlockRule
{
    public function id(): string
    {
        return 'consistent-table-columns';
    }

    public function enterBlock(BlockEvent $event, RuleContext $context): iterable
    {
        if (GfmExtension::TABLE_KIND !== $event->kind->name || !$event->model instanceof ParsedDocumentModel) {
            return;
        }

        [$header] = $event->model->tableParts($event->id->ordinal);
        $expected = \count($header);

        foreach ($event->model->tableBodyRows($event->id->ordinal) as [$cells, $range]) {
            $actual = \count($cells);

            if ($actual === $expected) {
                continue;
            }

            yield new LintProblem(
                $this->id(),
                \sprintf('Table row has %d columns; expected %d.', $actual, $expected),
                $range,
                severity: $context->config->severityFor($this->id()),
            );
        }
    }
}
