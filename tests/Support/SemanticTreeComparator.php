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

namespace Alto\Markdown\Tests\Support;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\DocumentModel;
use Alto\Markdown\Parser\ParseTape;

final class SemanticTreeComparator
{
    public function compare(DocumentModel $expected, DocumentModel $actual): SemanticTreeComparison
    {
        if (!$expected instanceof ParsedDocumentModel || !$actual instanceof ParsedDocumentModel) {
            throw new \InvalidArgumentException('SemanticTreeComparator requires parsed document models.');
        }

        return $this->compareNode(
            $expected,
            $expected->rootNodeId()->ordinal,
            $actual,
            $actual->rootNodeId()->ordinal,
            '/document',
        );
    }

    private function compareNode(
        ParsedDocumentModel $expected,
        int $expectedOrdinal,
        ParsedDocumentModel $actual,
        int $actualOrdinal,
        string $path,
    ): SemanticTreeComparison {
        $expectedId = $expected->currentNodeId($expectedOrdinal);
        $actualId = $actual->currentNodeId($actualOrdinal);
        $expectedKind = $expected->nodeKind($expectedId)->name;
        $actualKind = $actual->nodeKind($actualId)->name;

        if ($this->semanticKind($expectedKind) !== $this->semanticKind($actualKind)) {
            return SemanticTreeComparison::different(\sprintf(
                '%s kind differs: expected "%s", got "%s".',
                $path,
                $expectedKind,
                $actualKind,
            ));
        }

        $block = $this->compareBlockPayload($expected, $expectedOrdinal, $actual, $actualOrdinal, $path, $expectedKind, $actualKind);

        if (!$block->isEqual()) {
            return $block;
        }

        $inlines = $this->compareInlinePayloads($expected, $expectedOrdinal, $actual, $actualOrdinal, $path);

        if (!$inlines->isEqual()) {
            return $inlines;
        }

        return $this->compareChildren($expected, $expectedOrdinal, $actual, $actualOrdinal, $path);
    }

    private function semanticKind(string $kind): string
    {
        return match ($kind) {
            'fenced-code', 'indented-code' => 'code-block',
            default => $kind,
        };
    }

    private function compareBlockPayload(
        ParsedDocumentModel $expected,
        int $expectedOrdinal,
        ParsedDocumentModel $actual,
        int $actualOrdinal,
        string $path,
        string $expectedKind,
        string $actualKind,
    ): SemanticTreeComparison {
        $expectedPayload = $this->blockPayload($expected, $expectedOrdinal, $expectedKind);
        $actualPayload = $this->blockPayload($actual, $actualOrdinal, $actualKind);

        if ($expectedPayload === $actualPayload) {
            return SemanticTreeComparison::equal();
        }

        return SemanticTreeComparison::different(\sprintf(
            '%s payload differs: expected %s, got %s.',
            $path,
            json_encode($expectedPayload, \JSON_THROW_ON_ERROR),
            json_encode($actualPayload, \JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function blockPayload(ParsedDocumentModel $model, int $ordinal, string $kind): array
    {
        if ('atx-heading' === $kind || 'setext-heading' === $kind) {
            return [
                'level' => $model->headingLevel($ordinal),
                'text' => $model->plainText($ordinal),
            ];
        }

        if ('fenced-code' === $kind || 'indented-code' === $kind) {
            return [
                'language' => $model->codeBlockLanguage($ordinal),
                'code' => $model->codeBlockCode($ordinal),
            ];
        }

        if ('list' === $kind) {
            return [
                'ordered' => $model->listIsOrdered($ordinal),
                'loose' => $model->listIsLoose($ordinal),
                'start' => $model->listStartNumber($ordinal),
            ];
        }

        if ('link-reference-definition' === $kind) {
            return [
                'label' => $model->referenceDefinitionLabel($ordinal),
            ];
        }

        if ('list-item' === $kind) {
            return [
                'task' => $model->taskListState($ordinal),
            ];
        }

        if ('gfm:table' === $kind) {
            return [
                'parts' => $model->tableParts($ordinal),
            ];
        }

        if ('github:alert' === $kind) {
            return [
                'type' => $model->blockPayload($ordinal),
            ];
        }

        return [];
    }

    private function compareInlinePayloads(
        ParsedDocumentModel $expected,
        int $expectedOrdinal,
        ParsedDocumentModel $actual,
        int $actualOrdinal,
        string $path,
    ): SemanticTreeComparison {
        $expectedPayloads = $this->inlinePayloads($expected, $expectedOrdinal);
        $actualPayloads = $this->inlinePayloads($actual, $actualOrdinal);

        if ($expectedPayloads === $actualPayloads) {
            return SemanticTreeComparison::equal();
        }

        return SemanticTreeComparison::different(\sprintf(
            '%s inline payloads differ: expected %s, got %s.',
            $path,
            json_encode($expectedPayloads, \JSON_THROW_ON_ERROR),
            json_encode($actualPayloads, \JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inlinePayloads(ParsedDocumentModel $model, int $blockOrdinal): array
    {
        $payloads = [];

        foreach ($model->traversalInlineEvents($blockOrdinal) as [$inlineOrdinal, $kind]) {
            $text = match ($kind) {
                'text', 'code-span', 'autolink', 'html-inline' => $model->inlineLiteral($blockOrdinal, $inlineOrdinal),
                default => $model->inlinePlainText($blockOrdinal, $inlineOrdinal),
            };

            if ('text' === $kind && '' === $text) {
                continue;
            }

            if ('text' === $kind && [] !== $payloads && 'text' === $payloads[\count($payloads) - 1]['kind']) {
                $payloads[\count($payloads) - 1]['text'] .= $text;

                continue;
            }

            $payload = [
                'kind' => $kind,
                'text' => $text,
            ];

            if ('link' === $kind || 'image' === $kind) {
                [$destination, $title] = $model->inlinePayloadParts($blockOrdinal, $inlineOrdinal);
                $payload['destination'] = $destination;
                $payload['title'] = $title;
            }

            $payloads[] = $payload;
        }

        return $payloads;
    }

    private function compareChildren(
        ParsedDocumentModel $expected,
        int $expectedOrdinal,
        ParsedDocumentModel $actual,
        int $actualOrdinal,
        string $path,
    ): SemanticTreeComparison {
        $expectedChild = $expected->firstChildOrdinal($expectedOrdinal);
        $actualChild = $actual->firstChildOrdinal($actualOrdinal);
        $index = 0;

        while (ParseTape::NONE !== $expectedChild && ParseTape::NONE !== $actualChild) {
            $comparison = $this->compareNode(
                $expected,
                $expectedChild,
                $actual,
                $actualChild,
                $path . '[' . $index . ']',
            );

            if (!$comparison->isEqual()) {
                return $comparison;
            }

            $expectedChild = $expected->nextSiblingOrdinal($expectedChild);
            $actualChild = $actual->nextSiblingOrdinal($actualChild);
            ++$index;
        }

        if ($expectedChild !== $actualChild) {
            return SemanticTreeComparison::different(\sprintf('%s child count differs.', $path));
        }

        return SemanticTreeComparison::equal();
    }
}
