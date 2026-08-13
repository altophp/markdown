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

namespace Alto\Markdown\Parser\Inline;

use Alto\Markdown\Parser\Instrumentation;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;

/**
 * Link and image resolution at a closing bracket (CommonMark 0.31.2
 * "Links", "Images"): inline suffix "(dest \"title\")", full, collapsed
 * and shortcut references against the ReferenceMap. On a match the nodes
 * after the opening bracket become children of a LINK or IMAGE node,
 * emphasis inside the label is processed first through the sub-range
 * hook, and earlier link openers deactivate (no links inside links).
 *
 * Node payload convention: "href\x00title", both attribute-ready (href
 * percent-encoded, title decoded); a mutated image may append
 * "\x00alternative". The renderer HTML-escapes every value.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class LinkResolver
{
    public function __construct(
        private readonly EmphasisProcessor $emphasis,
    ) {}

    /**
     * Handles a "]" at $offset. Returns the content offset after the
     * consumed construct, or null when the bracket does not form a link
     * (the "]" stays literal; the failed opener is dropped).
     *
     * @param list<Bracket>   $brackets   modified in place
     * @param list<Delimiter> $delimiters modified in place
     */
    public function resolve(InlineState $state, InlineContent $content, array &$brackets, array &$delimiters, int $offset, EmphasisWrapper $wrapper): ?int
    {
        $bracket = array_pop($brackets);

        if (null === $bracket) {
            return null;
        }

        if (!$bracket->active) {
            return null;
        }

        $match = self::match($content->text, $bracket->contentOffset, $offset, $state->referenceMap());

        if (null === $match) {
            return null;
        }

        [$destination, $title, $end] = $match;
        $this->wrap($state, $content, $bracket, $delimiters, $offset, $end, $destination, $title, $wrapper);

        if (!$bracket->image) {
            foreach ($brackets as $earlier) {
                if (!$earlier->image) {
                    $earlier->active = false;
                }
            }
        }

        return $end;
    }

    /**
     * Decides whether the "]" at $offset closes a link or image whose
     * label content starts at $labelStart: the inline suffix form first,
     * then the full, collapsed, and shortcut reference forms against the
     * reference map (recording a resolved label as used). Pure syntax
     * plus map lookup, shared by both parsing lanes.
     *
     * @return array{string, string, int}|null [destination, title, content offset after the construct]
     */
    public static function match(string $text, int $labelStart, int $offset, ReferenceMap $references): ?array
    {
        if (Instrumentation::$timing) {
            ++Instrumentation::$refMatchCalls;
        }

        // Inline form first: [label](dest "title").
        if ($offset + 1 < \strlen($text) && '(' === $text[$offset + 1]) {
            $suffix = self::parseInlineSuffix($text, $offset + 1);

            if (null !== $suffix) {
                if (Instrumentation::$timing) {
                    ++Instrumentation::$refInlineSuffix;
                }

                return [$suffix['destination'], $suffix['title'], $suffix['end']];
            }
        }

        // Reference forms: full [label][ref], collapsed [label][], shortcut [label].
        $label = null;
        $end = $offset + 1;

        if ($offset + 1 < \strlen($text) && '[' === $text[$offset + 1]) {
            $close = self::findLabelEnd($text, $offset + 2);

            if (null !== $close && $close > $offset + 2) {
                // Full reference.
                $label = substr($text, $offset + 2, $close - $offset - 2);
                $end = $close + 1;
            } elseif (null !== $close) {
                // Collapsed reference: label is the bracket content.
                $label = substr($text, $labelStart, $offset - $labelStart);
                $end = $close + 1;
            }
        } else {
            // Shortcut reference.
            $label = substr($text, $labelStart, $offset - $labelStart);
        }

        if (null === $label || '' === trim($label) || \strlen($label) > 999) {
            if (Instrumentation::$timing) {
                ++Instrumentation::$refFailed;
            }

            return null;
        }

        if (Instrumentation::$timing) {
            ++Instrumentation::$refLabelLookups;
        }

        $definition = $references->resolve($label);

        if (null === $definition) {
            if (Instrumentation::$timing) {
                ++Instrumentation::$refLookupMisses;
                ++Instrumentation::$refFailed;
            }

            return null;
        }

        if (Instrumentation::$timing) {
            ++Instrumentation::$refLookupHits;
        }

        return [$definition['destination'], $definition['title'] ?? '', $end];
    }

    /**
     * Wraps everything after the opening bracket node into a LINK or
     * IMAGE node covering source bytes up to $end.
     *
     * @param list<Delimiter> $delimiters modified in place
     */
    private function wrap(InlineState $state, InlineContent $content, Bracket $bracket, array &$delimiters, int $labelEnd, int $end, string $destination, string $title, EmphasisWrapper $wrapper): void
    {
        $tape = $state->tape();
        $root = $tape->parentOrdinal($bracket->node);

        // Emphasis inside the label resolves first, bounded below by the
        // delimiters that existed when the bracket opened.
        if (Instrumentation::$timing) {
            Instrumentation::enter('emphasis');
        }

        $this->emphasis->process($wrapper, $delimiters, $bracket->delimiterIndex);

        if (Instrumentation::$timing) {
            Instrumentation::leave('emphasis');
        }

        array_splice($delimiters, $bracket->delimiterIndex);

        $kind = $bracket->image ? InlineKind::IMAGE : InlineKind::LINK;
        $payload = Href::encode(Href::resolve($destination)) . "\x00" . Href::resolve($title);
        $labelSourceEnd = $content->sourceOffset($labelEnd);
        $sourceEnd = $content->sourceOffset($end);

        $state->reserveNode($tape->startOffset($bracket->node));
        $node = $tape->allocateClosed(
            $kind,
            $root,
            $tape->startOffset($bracket->node),
            $sourceEnd,
            0,
            flags: $labelSourceEnd,
            payload: $payload,
        );

        // Children: the sibling chain after the opening bracket node.
        $firstInner = $tape->nextSiblingOrdinal($bracket->node);

        if (ParseTape::NONE !== $firstInner) {
            $tape->linkFirstChild($node, $firstInner);
        }

        // The opening bracket node disappears; the link takes its place.
        if (ParseTape::NONE === $bracket->previousSibling) {
            $tape->linkFirstChild($root, $node);
        } else {
            $tape->linkNextSibling($bracket->previousSibling, $node);
        }

        $tape->linkNextSibling($node, ParseTape::NONE);
        $state->continueAfter($node);
    }

    /**
     * @return array{destination: string, title: string, end: int}|null
     */
    private static function parseInlineSuffix(string $text, int $open): ?array
    {
        $length = \strlen($text);
        $pos = $open + 1;
        $pos += strspn($text, " \t\n", $pos);

        // Destination: <angle> or bare with balanced parens.
        $destination = '';

        if ($pos < $length && '<' === $text[$pos]) {
            ++$pos;
            $start = $pos;

            while ($pos < $length) {
                $byte = $text[$pos];

                if ('\\' === $byte && $pos + 1 < $length) {
                    $pos += 2;

                    continue;
                }

                if ('>' === $byte) {
                    break;
                }

                if ('<' === $byte || "\n" === $byte) {
                    return null;
                }

                ++$pos;
            }

            if ($pos >= $length) {
                return null;
            }

            $destination = substr($text, $start, $pos - $start);
            ++$pos;
        } else {
            $start = $pos;
            $depth = 0;

            while ($pos < $length) {
                $byte = $text[$pos];

                if ('\\' === $byte && $pos + 1 < $length) {
                    $pos += 2;

                    continue;
                }

                if (' ' === $byte || "\t" === $byte || "\n" === $byte) {
                    break;
                }

                if ('(' === $byte) {
                    ++$depth;
                } elseif (')' === $byte) {
                    if (0 === $depth) {
                        break;
                    }

                    --$depth;
                } elseif (\ord($byte) < 0x20) {
                    break;
                }

                ++$pos;
            }

            if ($depth > 0) {
                return null;
            }

            $destination = substr($text, $start, $pos - $start);
        }

        $afterDest = $pos;
        $pos += strspn($text, " \t\n", $pos);
        $title = '';

        if ($pos < $length && $pos > $afterDest && ('"' === $text[$pos] || "'" === $text[$pos] || '(' === $text[$pos])) {
            $quote = $text[$pos];
            $closeQuote = '(' === $quote ? ')' : $quote;
            ++$pos;
            $start = $pos;

            while ($pos < $length) {
                $byte = $text[$pos];

                if ('\\' === $byte && $pos + 1 < $length) {
                    $pos += 2;

                    continue;
                }

                if ($byte === $closeQuote) {
                    break;
                }

                if ('(' === $quote && '(' === $byte) {
                    return null;
                }

                ++$pos;
            }

            if ($pos >= $length) {
                return null;
            }

            $title = substr($text, $start, $pos - $start);
            ++$pos;
            $pos += strspn($text, " \t\n", $pos);
        }

        if ($pos >= $length || ')' !== $text[$pos]) {
            return null;
        }

        return ['destination' => $destination, 'title' => $title, 'end' => $pos + 1];
    }

    /**
     * Content offset of the "]" closing a reference label opened at $from,
     * or null; unescaped brackets terminate the label.
     */
    private static function findLabelEnd(string $text, int $from): ?int
    {
        $length = \strlen($text);
        $pos = $from;

        while ($pos < $length) {
            $byte = $text[$pos];

            if ('\\' === $byte && $pos + 1 < $length) {
                $pos += 2;

                continue;
            }

            if (']' === $byte) {
                return $pos;
            }

            if ('[' === $byte) {
                return null;
            }

            ++$pos;
        }

        return null;
    }
}
