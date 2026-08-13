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

namespace Alto\Markdown\Tests\Conformance;

/**
 * Parses a CommonMark or GFM spec test JSON string into SpecExample objects.
 *
 * Pure by design: a string goes in and value objects come out, so the parsing
 * can be unit-tested without touching the filesystem. Any structural problem
 * raises MalformedSpecException rather than yielding a half-built example.
 */
final class SpecExampleLoader
{
    /**
     * @return list<SpecExample>
     */
    public function load(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MalformedSpecException('Spec JSON is not valid JSON: ' . $exception->getMessage(), previous: $exception);
        }

        if (!\is_array($decoded) || !array_is_list($decoded)) {
            throw new MalformedSpecException('Spec JSON must be a JSON array of examples.');
        }

        $examples = [];
        foreach ($decoded as $index => $entry) {
            $examples[] = self::parseEntry($entry, $index);
        }

        return $examples;
    }

    private static function parseEntry(mixed $entry, int $index): SpecExample
    {
        if (!\is_array($entry)) {
            throw new MalformedSpecException(sprintf('Example at index %d must be a JSON object.', $index));
        }

        return new SpecExample(
            markdown: self::stringField($entry, 'markdown', $index),
            html: self::stringField($entry, 'html', $index),
            example: self::intField($entry, 'example', $index),
            section: self::stringField($entry, 'section', $index),
            startLine: self::intField($entry, 'start_line', $index),
            endLine: self::intField($entry, 'end_line', $index),
        );
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function stringField(array $entry, string $key, int $index): string
    {
        $value = self::field($entry, $key, $index);

        if (!\is_string($value)) {
            throw new MalformedSpecException(sprintf('Example at index %d field "%s" must be a string.', $index, $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function intField(array $entry, string $key, int $index): int
    {
        $value = self::field($entry, $key, $index);

        if (!\is_int($value)) {
            throw new MalformedSpecException(sprintf('Example at index %d field "%s" must be an integer.', $index, $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function field(array $entry, string $key, int $index): mixed
    {
        if (!\array_key_exists($key, $entry)) {
            throw new MalformedSpecException(sprintf('Example at index %d is missing the "%s" field.', $index, $key));
        }

        return $entry[$key];
    }
}
