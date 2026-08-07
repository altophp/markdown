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

namespace Alto\Markdown\Document\Handle;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Operation\SourcePatch;
use Alto\Markdown\Operation\SourcePatchOperation;
use Alto\Markdown\Source\SourceRange;

/**
 * The one heading rename, shared by Heading::rename() and Section::rename().
 *
 * It applies the new title to the tape so the next read returns it, and records
 * one source patch over the heading line only. An ATX heading line is rebuilt
 * from the block's level, which drops any closing hash run; a setext heading
 * keeps its underline and only its title line changes.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HeadingRename
{
    public function __construct(private ParsedDocumentModel $model)
    {
    }

    /**
     * @param string $subject the word used in the journal description, "heading" or "section"
     *
     * @return NodeId the renamed heading's identity at the new generation
     */
    public function apply(NodeId $headingId, string $title, string $subject): NodeId
    {
        $range = $this->model->range($headingId);
        $description = \sprintf('rename %s "%s" to "%s"', $subject, $this->model->plainText($headingId->ordinal), $title);
        $replacement = $this->replacement($headingId, $range, $title);
        $renamed = $this->model->replaceHeadingMarkdown($headingId, $title);

        $this->model->journal()->record(
            new SourcePatchOperation(new SourcePatch($range, $replacement, $range, $description)),
            $range,
        );

        return $renamed;
    }

    private function replacement(NodeId $headingId, SourceRange $range, string $title): string
    {
        $original = \substr($this->model->source()->bytes, $range->startOffset, $range->endOffset - $range->startOffset);
        $trimmed = ltrim($original);

        if (str_starts_with($trimmed, '#')) {
            return str_repeat('#', $this->model->headingLevel($headingId->ordinal)).' '.$title;
        }

        if (str_contains($original, "\r\n")) {
            [, $underline] = explode("\r\n", $original, 3) + ['', ''];

            return $title."\r\n".$underline;
        }

        if (str_contains($original, "\n")) {
            [, $underline] = explode("\n", $original, 3) + ['', ''];

            return $title."\n".$underline;
        }

        if (str_contains($original, "\r")) {
            [, $underline] = explode("\r", $original, 3) + ['', ''];

            return $title."\r".$underline;
        }

        return $title;
    }
}
