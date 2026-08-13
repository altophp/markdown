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

namespace Alto\Markdown\Parser;

use Alto\Markdown\Exception\ReferenceCountLimitException;

/**
 * Document-wide store of link reference definitions: a normalized label maps
 * to its destination and optional title. The first definition of a label
 * wins; later definitions of the same normalized label are ignored. Inline
 * link resolution (step 3) reads this map to turn reference links and images
 * into destinations.
 *
 * Label normalization follows CommonMark: strip the surrounding brackets,
 * strip leading and trailing whitespace, collapse internal whitespace runs to
 * a single space, then apply a full Unicode case fold (see CaseFold).
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class ReferenceMap
{
    /**
     * @var array<string, array{destination: string, title: string|null}>
     */
    private array $definitions = [];

    /**
     * @var array<string, true>
     */
    private array $used = [];

    public function __construct(
        private readonly int $maxReferenceCount = ParseOptions::UNBOUNDED_REFERENCE_COUNT,
    ) {}

    /**
     * Records a definition unless its normalized label is empty or already
     * defined. Returns true when stored, false when ignored.
     */
    public function add(string $label, string $destination, ?string $title, int $byteOffset = 0): bool
    {
        return $this->store(self::normalize($label), $destination, $title, $byteOffset);
    }

    /**
     * Records a definition and returns the normalized label used by the map.
     * Block parsing can persist that label without normalizing the source a
     * second time.
     */
    public function define(string $label, string $destination, ?string $title, int $byteOffset = 0): string
    {
        $key = self::normalize($label);
        $this->store($key, $destination, $title, $byteOffset);

        return $key;
    }

    /**
     * Looks up a definition and records a successful resolution as used.
     * The label is normalized once for both operations.
     *
     * @return array{destination: string, title: string|null}|null
     */
    public function resolve(string $label): ?array
    {
        $key = self::normalize($label);
        $definition = $this->definitions[$key] ?? null;

        if (null !== $definition) {
            $this->used[$key] = true;
        }

        return $definition;
    }

    private function store(string $key, string $destination, ?string $title, int $byteOffset): bool
    {
        if ('' === $key || isset($this->definitions[$key])) {
            return false;
        }

        $attemptedCount = \count($this->definitions) + 1;

        if (ParseOptions::UNBOUNDED_REFERENCE_COUNT !== $this->maxReferenceCount
            && $attemptedCount > $this->maxReferenceCount
        ) {
            throw new ReferenceCountLimitException($this->maxReferenceCount, $attemptedCount, $byteOffset);
        }

        $this->definitions[$key] = ['destination' => $destination, 'title' => $title];

        return true;
    }

    /**
     * @return array{destination: string, title: string|null}|null
     */
    public function lookup(string $label): ?array
    {
        return $this->definitions[self::normalize($label)] ?? null;
    }

    public function has(string $label): bool
    {
        return isset($this->definitions[self::normalize($label)]);
    }

    public function count(): int
    {
        return \count($this->definitions);
    }

    public function markUsed(string $label): void
    {
        $key = self::normalize($label);

        if (isset($this->definitions[$key])) {
            $this->used[$key] = true;
        }
    }

    /**
     * @return list<string>
     */
    public function unusedLabels(): array
    {
        $unused = [];

        foreach (array_keys($this->definitions) as $label) {
            if (!isset($this->used[$label])) {
                $unused[] = $label;
            }
        }

        return $unused;
    }

    /**
     * Bytes that force the slow normalization path: ASCII uppercase (case
     * folds), the six label whitespace bytes (trim and collapse), and every
     * high byte (may be a case-foldable codepoint). A label free of all of
     * them is already its own normalized key.
     */
    private const string SLOW_BYTES = "ABCDEFGHIJKLMNOPQRSTUVWXYZ \t\r\n\f\x0b"
        . "\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8a\x8b\x8c\x8d\x8e\x8f"
        . "\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9a\x9b\x9c\x9d\x9e\x9f"
        . "\xa0\xa1\xa2\xa3\xa4\xa5\xa6\xa7\xa8\xa9\xaa\xab\xac\xad\xae\xaf"
        . "\xb0\xb1\xb2\xb3\xb4\xb5\xb6\xb7\xb8\xb9\xba\xbb\xbc\xbd\xbe\xbf"
        . "\xc0\xc1\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xcb\xcc\xcd\xce\xcf"
        . "\xd0\xd1\xd2\xd3\xd4\xd5\xd6\xd7\xd8\xd9\xda\xdb\xdc\xdd\xde\xdf"
        . "\xe0\xe1\xe2\xe3\xe4\xe5\xe6\xe7\xe8\xe9\xea\xeb\xec\xed\xee\xef"
        . "\xf0\xf1\xf2\xf3\xf4\xf5\xf6\xf7\xf8\xf9\xfa\xfb\xfc\xfd\xfe\xff";

    /**
     * Normalized matching key for a link label. Accepts either the bare label
     * text or the bracketed form; a single surrounding bracket pair is
     * stripped first.
     */
    public static function normalize(string $label): string
    {
        if (Instrumentation::$timing) {
            ++Instrumentation::$normalizeCalls;
        }

        $length = \strlen($label);

        if ($length >= 2 && '[' === $label[0] && ']' === $label[$length - 1]) {
            $label = substr($label, 1, -1);
            $length -= 2;
        }

        // Fast path: a label with no uppercase, no whitespace, and no high
        // byte needs neither trimming, collapsing, nor case folding, so it is
        // already its own key. This covers the common ASCII slug label.
        if (strcspn($label, self::SLOW_BYTES) === $length) {
            if (Instrumentation::$timing) {
                ++Instrumentation::$normalizeFastPath;
            }

            return $label;
        }

        if (Instrumentation::$timing) {
            ++Instrumentation::$caseFoldCalls;
            Instrumentation::$caseFoldBytes += $length;
        }

        $collapsed = preg_replace('/[ \t\r\n\f\x0b]+/', ' ', trim($label, " \t\r\n\f\x0b"));

        return CaseFold::fold($collapsed ?? '');
    }
}
