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

use Alto\Markdown\Extension\Gfm\GfmExtension;
use Alto\Markdown\Parser\Block\BlockKind;
use Alto\Markdown\Parser\Block\GfmTableParser;
use Alto\Markdown\Parser\BlockParser;
use Alto\Markdown\Parser\Inline\HtmlEntities;
use Alto\Markdown\Parser\Inline\InlineKind;
use Alto\Markdown\Parser\Inline\InlineParser;
use Alto\Markdown\Parser\InlineCache;
use Alto\Markdown\Parser\Input\LineMap;
use Alto\Markdown\Parser\Input\LineScanner;
use Alto\Markdown\Parser\Input\SourceBuffer;
use Alto\Markdown\Parser\ParserState;
use Alto\Markdown\Parser\ParseTape;
use Alto\Markdown\Parser\ReferenceMap;
use Alto\Markdown\Profile\CompiledProfile;
use Alto\Markdown\Profile\ProfileCompiler;

/**
 * Test-only block-level HTML renderer for conformance scoring. Inline
 * content is emitted as raw escaped text (no inline parsing) plus the
 * hard-line-break rule, because paragraph examples encode it.
 *
 * Tape conventions consumed here (wave-B constructs must write them):
 * - ATX_HEADING, SETEXT_HEADING: flags = level 1..6; payload = "cs:ce"
 *   content byte range with markers excluded.
 * - FENCED_CODE: flags = opening fence indent (0..3); payload stores code
 *   ranges before `|`, optional `!` closure state immediately before `|`, and
 *   the trimmed info string after `|`.
 * - INDENTED_CODE: payload = "cs:ce" spanning first to last code line
 *   (indent included); up to 4 columns of indent are stripped per line.
 * - BLOCK_QUOTE, LIST_ITEM: containers, children rendered recursively.
 * - LIST: flags bit 0 = ordered, bit 1 = loose; payload = start number
 *   for ordered lists.
 * - THEMATIC_BREAK: no data. HTML_BLOCK: block range emitted verbatim.
 * - LINK_REFERENCE_DEFINITION: renders nothing.
 */
final class TestHtmlRenderer implements HtmlRenderer
{
    private const int LIST_ORDERED = 1;
    private const int LIST_LOOSE = 2;

    private readonly InlineParser $inline;

    private readonly CompiledProfile $profile;

    private readonly bool $gfmLike;

    private readonly ?int $tableKind;

    private InlineCache $cache;

    private ReferenceMap $referenceMap;

    public function __construct(?CompiledProfile $profile = null)
    {
        $this->profile = $profile ?? ProfileCompiler::commonmark();
        $this->gfmLike = $this->profile->strikethrough;
        $this->tableKind = $this->profile->nodeKinds->find(GfmExtension::TABLE_KIND)?->id;
        $this->inline = new InlineParser($this->profile);
        $this->cache = new InlineCache();
        $this->referenceMap = new ReferenceMap();
    }

    public function render(string $markdown): string
    {
        $buffer = new SourceBuffer($markdown);
        $scanner = new LineScanner($buffer);
        $map = new LineMap($buffer, $scanner);
        $tape = new ParseTape();
        $state = new ParserState($buffer, $scanner, $map, $tape);

        $blockParser = new BlockParser($this->profile);
        $document = $blockParser->parse($state);
        $this->cache = new InlineCache();
        $this->referenceMap = $blockParser->referenceMap();

        return $this->renderChildren($buffer, $tape, $document, false);
    }

    /**
     * Renders a leaf block's content through the inline layer; the cache
     * is keyed by block ordinal and generation like the product path.
     * $hardBreaks off maps hard breaks to newlines (headings).
     */
    private function inlineHtml(SourceBuffer $buffer, ParseTape $tape, int $ordinal, bool $hardBreaks = true): string
    {
        $generation = $tape->generation($ordinal);
        $inlineTape = $this->cache->get($ordinal, $generation);

        if (null === $inlineTape) {
            $inlineTape = $this->inline->parse($buffer, $this->contentPairs($tape, $ordinal), $this->referenceMap);
            $this->cache->put($ordinal, $generation, $inlineTape);
        }

        return $this->renderInlineChildren($buffer, $inlineTape, 0, $hardBreaks);
    }

    /**
     * Inline tape conventions (wave-2/3 constructs write them):
     * - TEXT: payload, when set, is the resolved text (escapes, entities);
     *   otherwise the source slice is the text.
     * - CODE_SPAN: payload is the processed code content.
     * - AUTOLINK: payload is the ready href; the slice is the label.
     * - HTML_INLINE: the slice is emitted verbatim.
     * - EMPHASIS/STRONG/LINK/IMAGE: children render recursively; LINK and
     *   IMAGE payload is "href\x00title" (title may be empty).
     */
    private function renderInlineChildren(SourceBuffer $buffer, ParseTape $inlineTape, int $parent, bool $hardBreaks, bool $insideStrong = false): string
    {
        $html = '';
        $node = $inlineTape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $node) {
            $slice = fn (): string => $buffer->substring($inlineTape->startOffset($node), $inlineTape->endOffset($node));
            $html .= match ($inlineTape->kindId($node)) {
                InlineKind::TEXT => $this->escape($inlineTape->payload($node) ?? $slice()),
                InlineKind::SOFT_BREAK => "\n",
                InlineKind::HARD_BREAK => $hardBreaks ? "<br />\n" : "\n",
                InlineKind::CODE_SPAN => '<code>'.$this->escape($inlineTape->payload($node) ?? $slice()).'</code>',
                InlineKind::AUTOLINK => '<a href="'.$this->escape($inlineTape->payload($node) ?? '').'">'.$this->escape($slice()).'</a>',
                InlineKind::HTML_INLINE => $this->rawHtml($inlineTape->payload($node) ?? $slice()),
                InlineKind::EMPHASIS => '<em>'.$this->renderInlineChildren($buffer, $inlineTape, $node, $hardBreaks).'</em>',
                InlineKind::STRONG => $this->strong($buffer, $inlineTape, $node, $hardBreaks, $insideStrong),
                InlineKind::STRIKETHROUGH => '<del>'.$this->renderInlineChildren($buffer, $inlineTape, $node, $hardBreaks).'</del>',
                InlineKind::LINK => $this->link($buffer, $inlineTape, $node, $hardBreaks),
                InlineKind::IMAGE => $this->image($buffer, $inlineTape, $node),
                default => throw new \RuntimeException(\sprintf('Inline kind %d is not renderable yet.', $inlineTape->kindId($node))),
            };
            $node = $inlineTape->nextSiblingOrdinal($node);
        }

        return $html;
    }

    private function renderChildren(SourceBuffer $buffer, ParseTape $tape, int $parent, bool $tight): string
    {
        $html = '';
        $child = $tape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $child) {
            $html .= $this->renderBlock($buffer, $tape, $child, $tight);
            $child = $tape->nextSiblingOrdinal($child);
        }

        return $html;
    }

    private function renderBlock(SourceBuffer $buffer, ParseTape $tape, int $ordinal, bool $tight): string
    {
        $kind = $tape->kindId($ordinal);

        if ($this->tableKind === $kind) {
            return $this->table($buffer, $tape, $ordinal);
        }

        return match ($kind) {
            BlockKind::PARAGRAPH => $tight
                ? $this->paragraphContent($buffer, $tape, $ordinal)."\n"
                : '<p>'.$this->paragraphContent($buffer, $tape, $ordinal)."</p>\n",
            BlockKind::ATX_HEADING, BlockKind::SETEXT_HEADING => $this->heading($buffer, $tape, $ordinal),
            BlockKind::THEMATIC_BREAK => "<hr />\n",
            BlockKind::INDENTED_CODE => $this->indentedCode($buffer, $tape, $ordinal),
            BlockKind::FENCED_CODE => $this->fencedCode($buffer, $tape, $ordinal),
            BlockKind::HTML_BLOCK => $this->htmlBlock($buffer, $tape, $ordinal),
            BlockKind::BLOCK_QUOTE => "<blockquote>\n".$this->renderChildren($buffer, $tape, $ordinal, false).'</blockquote>'."\n",
            BlockKind::LIST => $this->list($buffer, $tape, $ordinal),
            BlockKind::LIST_ITEM => $this->listItem($buffer, $tape, $ordinal, $tight),
            BlockKind::LINK_REFERENCE_DEFINITION => '',
            default => throw new \RuntimeException(\sprintf('Block kind %d is not renderable.', $kind)),
        };
    }

    private function heading(SourceBuffer $buffer, ParseTape $tape, int $ordinal): string
    {
        $level = $tape->flags($ordinal);
        // ATX headings are single-line, so hard breaks cannot occur; a
        // multi-line setext heading honors them (spec 6.7: a hard break
        // separates inline content within a block).
        $hardBreaks = BlockKind::SETEXT_HEADING === $tape->kindId($ordinal);
        $text = $this->inlineHtml($buffer, $tape, $ordinal, hardBreaks: $hardBreaks);

        return \sprintf("<h%d>%s</h%d>\n", $level, $text, $level);
    }

    private function indentedCode(SourceBuffer $buffer, ParseTape $tape, int $ordinal): string
    {
        $code = '';

        foreach ($this->contentPairs($tape, $ordinal) as [$start, $end, $pad, $column]) {
            $code .= $this->stripColumns($buffer->substring($start, $end), 4, $pad, $column)."\n";
        }

        return '<pre><code>'.$this->escape($code).'</code></pre>'."\n";
    }

    private function fencedCode(SourceBuffer $buffer, ParseTape $tape, int $ordinal): string
    {
        $payload = $tape->payload($ordinal) ?? '|';
        $separator = strpos($payload, '|');
        $pairsPart = false === $separator ? '' : substr($payload, 0, $separator);
        $info = false === $separator ? '' : substr($payload, $separator + 1);

        if (str_ends_with($pairsPart, '!')) {
            $pairsPart = substr($pairsPart, 0, -1);
        }

        $indent = $tape->flags($ordinal);
        $code = '';

        if (str_starts_with($pairsPart, '@')) {
            [$start, $end, $appendLf] = array_pad(array_map('intval', explode(':', substr($pairsPart, 1), 3)), 3, 0);
            $code = $buffer->substring($start, $end);

            if (str_contains($code, "\r")) {
                $code = str_replace(["\r\n", "\r"], "\n", $code);
            }

            if (1 === $appendLf) {
                $code .= "\n";
            }
        } elseif ('' !== $pairsPart) {
            foreach (explode(';', $pairsPart) as $pair) {
                $parts = array_map('intval', explode(':', $pair));
                $code .= $this->stripFenceIndent($buffer->substring($parts[0], $parts[1]), $indent, (int) ($parts[2] ?? 0))."\n";
            }
        }

        $attribute = '';

        if ('' !== $info) {
            $word = explode(' ', $this->decodeInfo($info))[0];
            $attribute = ' class="language-'.$this->escape($word).'"';
        }

        return '<pre><code'.$attribute.'>'.$this->escape($code).'</code></pre>'."\n";
    }

    private function htmlBlock(SourceBuffer $buffer, ParseTape $tape, int $ordinal): string
    {
        $lines = [];

        foreach ($this->contentPairs($tape, $ordinal) as [$start, $end, $pad]) {
            $lines[] = str_repeat(' ', $pad).$buffer->substring($start, $end);
        }

        return $this->rawBlockHtml(implode("\n", $lines))."\n";
    }

    private function list(SourceBuffer $buffer, ParseTape $tape, int $ordinal): string
    {
        $flags = $tape->flags($ordinal);
        $ordered = 0 !== ($flags & self::LIST_ORDERED);
        $tight = 0 === ($flags & self::LIST_LOOSE);
        $items = $this->renderChildren($buffer, $tape, $ordinal, $tight);

        if ($ordered) {
            $startNumber = $tape->payload($ordinal) ?? '1';
            $attribute = '1' === $startNumber ? '' : ' start="'.$startNumber.'"';

            return '<ol'.$attribute.">\n".$items."</ol>\n";
        }

        return "<ul>\n".$items."</ul>\n";
    }

    private function listItem(SourceBuffer $buffer, ParseTape $tape, int $ordinal, bool $tight): string
    {
        $parts = [];
        $firstIsInline = false;
        $lastIsInline = false;
        $task = $this->taskCheckbox($tape, $ordinal);
        $child = $tape->firstChildOrdinal($ordinal);
        $first = true;

        while (ParseTape::NONE !== $child) {
            if ($tight && BlockKind::PARAGRAPH === $tape->kindId($child)) {
                $parts[] = ($first ? $task : '').$this->paragraphContent($buffer, $tape, $child);
                $lastIsInline = true;

                if ($first) {
                    $task = '';
                    $firstIsInline = true;
                }
            } elseif ($first && '' !== $task && BlockKind::PARAGRAPH === $tape->kindId($child)) {
                // The task marker belongs to the first paragraph's inline
                // content, so a loose item keeps it inside the <p>.
                $parts[] = '<p>'.$task.$this->paragraphContent($buffer, $tape, $child).'</p>';
                $task = '';
                $lastIsInline = false;
            } else {
                $rendered = rtrim($this->renderBlock($buffer, $tape, $child, $tight), "\n");

                // Nothing-rendering children (reference definitions) must
                // not leave a stray joint line in the item.
                if ('' !== $rendered) {
                    $parts[] = $rendered;
                    $lastIsInline = false;
                } else {
                    $child = $tape->nextSiblingOrdinal($child);

                    continue;
                }
            }

            $first = false;
            $child = $tape->nextSiblingOrdinal($child);
        }

        if ([] === $parts) {
            return "<li></li>\n";
        }

        // Inline content hugs its li tag; block content gets its own line.
        $prefix = $firstIsInline ? '' : "\n";
        $suffix = $lastIsInline ? '' : "\n";

        return '<li>'.$prefix.$task.implode("\n", $parts).$suffix."</li>\n";
    }

    private function taskCheckbox(ParseTape $tape, int $ordinal): string
    {
        return match ($tape->payload($ordinal)) {
            'task:unchecked' => '<input disabled="" type="checkbox"> ',
            'task:checked' => '<input checked="" disabled="" type="checkbox"> ',
            default => '',
        };
    }

    private function rawHtml(string $html): string
    {
        if (!$this->profile->tagFilter) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<(?=\\/?(?:title|textarea|style|xmp|iframe|noembed|noframes|script|plaintext)(?:\\s|>|\\/))/i',
            static fn (): string => '&lt;',
            $html,
        );
    }

    private function rawBlockHtml(string $html): string
    {
        if (!$this->profile->tagFilter || 1 === preg_match('/^<\\/?(?:title|textarea|style|xmp|iframe|noembed|noframes|script|plaintext)(?:\\s|>|\\/)/i', $html)) {
            return $html;
        }

        return $this->rawHtml($html);
    }

    private function paragraphContent(SourceBuffer $buffer, ParseTape $tape, int $ordinal): string
    {
        return $this->inlineHtml($buffer, $tape, $ordinal);
    }

    private function table(SourceBuffer $buffer, ParseTape $tape, int $ordinal): string
    {
        [$header, $alignments, $body] = $this->tableParts($buffer, $tape, $ordinal);
        $html = "<table>\n<thead>\n<tr>\n";

        foreach ($header as $index => $cell) {
            $html .= '<th'.$this->alignAttribute($alignments[$index] ?? '').'>'.$this->inlineCellHtml($cell)."</th>\n";
        }

        $html .= "</tr>\n</thead>\n";

        if ([] !== $body) {
            $html .= "<tbody>\n";

            foreach ($body as $row) {
                $html .= "<tr>\n";

                foreach ($header as $index => $_cell) {
                    $html .= '<td'.$this->alignAttribute($alignments[$index] ?? '').'>'.$this->inlineCellHtml($row[$index] ?? '')."</td>\n";
                }

                $html .= "</tr>\n";
            }

            $html .= "</tbody>\n";
        }

        return $html."</table>\n";
    }

    /**
     * @return array{list<string>, list<string>, list<list<string>>}
     */
    private function tableParts(SourceBuffer $buffer, ParseTape $tape, int $ordinal): array
    {
        $payload = $tape->payload($ordinal) ?? '||';
        [$headerPart, $alignmentPart, $bodyPart] = array_pad(explode('|', $payload, 3), 3, '');
        $header = GfmTableParser::splitRow($this->lineFromPair($buffer, $headerPart));
        $alignments = '' === $alignmentPart ? [] : explode(',', $alignmentPart);
        $body = [];

        if ('' !== $bodyPart) {
            foreach (explode(';', $bodyPart) as $pair) {
                $row = GfmTableParser::splitRow($this->lineFromPair($buffer, $pair));
                $body[] = \array_slice($row, 0, \count($header));
            }
        }

        return [$header, $alignments, $body];
    }

    private function lineFromPair(SourceBuffer $buffer, string $pair): string
    {
        $parts = explode(':', $pair, 3);

        return $buffer->substring((int) $parts[0], (int) ($parts[1] ?? 0));
    }

    private function alignAttribute(string $alignment): string
    {
        return '' === $alignment ? '' : ' align="'.$alignment.'"';
    }

    private function inlineCellHtml(string $cell): string
    {
        $cellBuffer = new SourceBuffer($cell);
        $inlineTape = $this->inline->parse($cellBuffer, [[0, \strlen($cell), 0]], $this->referenceMap);

        return $this->renderInlineChildren($cellBuffer, $inlineTape, 0, true);
    }

    /**
     * Content byte ranges from a "cs:ce[:pad][;...]" payload; falls back to
     * the block's own span when no payload was recorded. The third element
     * is the pad column count from partially consumed tabs.
     *
     * @return list<array{int, int, int, int}>
     */
    private function contentPairs(ParseTape $tape, int $ordinal): array
    {
        $payload = $tape->payload($ordinal);

        if (null === $payload || '' === $payload) {
            return [[$tape->startOffset($ordinal), $tape->endOffset($ordinal), 0, -1]];
        }

        $pairs = [];

        foreach (explode(';', $payload) as $pair) {
            $parts = explode(':', $pair, 4);
            $pairs[] = [(int) $parts[0], (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0), isset($parts[3]) ? (int) $parts[3] : -1];
        }

        return $pairs;
    }

    /**
     * Removes up to $columns virtual columns of indentation. $pad virtual
     * spaces precede the bytes (partially consumed tabs); $startColumn is
     * the absolute column of the first byte, so interior tabs expand
     * correctly (-1: treat the slice as starting at column 0).
     */
    private function stripColumns(string $line, int $columns, int $pad = 0, int $startColumn = -1): string
    {
        $base = $startColumn >= 0 ? $startColumn - $pad : 0;
        $goal = $base + $columns;

        // Consume pad columns first; the survivors render as spaces.
        $column = $base;
        $remainingPad = $pad;

        while ($remainingPad > 0 && $column < $goal) {
            --$remainingPad;
            ++$column;
        }

        $prefix = str_repeat(' ', $remainingPad);
        $byteColumn = $startColumn >= 0 ? $startColumn : $column;

        if ($byteColumn >= $goal) {
            return $prefix.$line;
        }

        $offset = 0;
        $column = $byteColumn;
        $length = \strlen($line);

        while ($offset < $length && $column < $goal) {
            $byte = $line[$offset];

            if (' ' === $byte) {
                ++$column;
                ++$offset;
            } elseif ("\t" === $byte) {
                $column += 4 - ($column % 4);
                ++$offset;
            } else {
                break;
            }
        }

        $overshoot = $column > $goal ? $column - $goal : 0;

        return $prefix.str_repeat(' ', $overshoot).substr($line, $offset);
    }

    private function stripFenceIndent(string $line, int $spaces, int $pad = 0): string
    {
        $removedPad = min($pad, $spaces);
        $pad -= $removedPad;
        $spaces -= $removedPad;
        $offset = 0;
        $length = \strlen($line);

        while ($offset < $length && $spaces > 0 && ' ' === $line[$offset]) {
            --$spaces;
            ++$offset;
        }

        return str_repeat(' ', $pad).substr($line, $offset);
    }

    private function link(SourceBuffer $buffer, ParseTape $inlineTape, int $node, bool $hardBreaks): string
    {
        [$href, $title] = explode("\x00", ($inlineTape->payload($node) ?? "\x00")."\x00");
        $attribute = '' !== $title ? ' title="'.$this->escape($title).'"' : '';

        return '<a href="'.$this->escape($href).'"'.$attribute.'>'.$this->renderLinkText($buffer, $inlineTape, $node, $hardBreaks).'</a>';
    }

    private function strong(SourceBuffer $buffer, ParseTape $inlineTape, int $node, bool $hardBreaks, bool $insideStrong): string
    {
        $children = $this->renderInlineChildren($buffer, $inlineTape, $node, $hardBreaks, true);

        if ($insideStrong && $this->gfmLike) {
            return $children;
        }

        return '<strong>'.$children.'</strong>';
    }

    private function renderLinkText(SourceBuffer $buffer, ParseTape $inlineTape, int $parent, bool $hardBreaks, bool $insideStrong = false): string
    {
        $html = '';
        $node = $inlineTape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $node) {
            $slice = fn (): string => $buffer->substring($inlineTape->startOffset($node), $inlineTape->endOffset($node));
            $html .= match ($inlineTape->kindId($node)) {
                InlineKind::TEXT => $this->escape($inlineTape->payload($node) ?? $slice()),
                InlineKind::SOFT_BREAK => "\n",
                InlineKind::HARD_BREAK => $hardBreaks ? "<br />\n" : "\n",
                InlineKind::CODE_SPAN => '<code>'.$this->escape($inlineTape->payload($node) ?? $slice()).'</code>',
                InlineKind::AUTOLINK => $this->escape($slice()),
                InlineKind::HTML_INLINE => $inlineTape->payload($node) ?? $slice(),
                InlineKind::EMPHASIS => '<em>'.$this->renderLinkText($buffer, $inlineTape, $node, $hardBreaks).'</em>',
                InlineKind::STRONG => $this->strongLinkText($buffer, $inlineTape, $node, $hardBreaks, $insideStrong),
                InlineKind::STRIKETHROUGH => '<del>'.$this->renderLinkText($buffer, $inlineTape, $node, $hardBreaks).'</del>',
                InlineKind::IMAGE => $this->image($buffer, $inlineTape, $node),
                default => throw new \RuntimeException(\sprintf('Inline kind %d is not renderable in link text.', $inlineTape->kindId($node))),
            };
            $node = $inlineTape->nextSiblingOrdinal($node);
        }

        return $html;
    }

    private function strongLinkText(SourceBuffer $buffer, ParseTape $inlineTape, int $node, bool $hardBreaks, bool $insideStrong): string
    {
        $children = $this->renderLinkText($buffer, $inlineTape, $node, $hardBreaks, true);

        if ($insideStrong && $this->gfmLike) {
            return $children;
        }

        return '<strong>'.$children.'</strong>';
    }

    private function image(SourceBuffer $buffer, ParseTape $inlineTape, int $node): string
    {
        [$src, $title] = explode("\x00", ($inlineTape->payload($node) ?? "\x00")."\x00");
        $attribute = '' !== $title ? ' title="'.$this->escape($title).'"' : '';

        return '<img src="'.$this->escape($src).'" alt="'.$this->escape($this->altText($buffer, $inlineTape, $node)).'"'.$attribute.' />';
    }

    /**
     * Image alt text is the label's plain text: markup drops, nested
     * images contribute their own alt.
     */
    private function altText(SourceBuffer $buffer, ParseTape $inlineTape, int $parent): string
    {
        $text = '';
        $node = $inlineTape->firstChildOrdinal($parent);

        while (ParseTape::NONE !== $node) {
            $text .= match ($inlineTape->kindId($node)) {
                InlineKind::TEXT, InlineKind::CODE_SPAN => $inlineTape->payload($node) ?? $buffer->substring($inlineTape->startOffset($node), $inlineTape->endOffset($node)),
                InlineKind::SOFT_BREAK, InlineKind::HARD_BREAK => "\n",
                InlineKind::AUTOLINK, InlineKind::HTML_INLINE => $buffer->substring($inlineTape->startOffset($node), $inlineTape->endOffset($node)),
                default => $this->altText($buffer, $inlineTape, $node),
            };
            $node = $inlineTape->nextSiblingOrdinal($node);
        }

        return $text;
    }

    /**
     * Fence info strings resolve backslash escapes and entity references
     * (spec examples 24, 34); they are not inline content, so the decode
     * happens here rather than in the inline layer.
     */
    private function decodeInfo(string $info): string
    {
        $info = preg_replace_callback(
            '/\\\\([!-\/:-@\[-`{-~])/',
            static fn (array $m): string => $m[1],
            $info,
        ) ?? $info;

        return preg_replace_callback(
            '/&(?:([A-Za-z][A-Za-z0-9]{1,47})|#([0-9]{1,7})|#[xX]([0-9A-Fa-f]{1,6}));/',
            static function (array $m): string {
                if ('' !== $m[1]) {
                    return HtmlEntities::decode($m[1]) ?? $m[0];
                }

                $code = '' !== $m[2] ? (int) $m[2] : (int) hexdec($m[3]);

                if ($code <= 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
                    $code = 0xFFFD;
                }

                return match (true) {
                    $code < 0x80 => \chr($code),
                    $code < 0x800 => \chr(0xC0 | $code >> 6).\chr(0x80 | $code & 0x3F),
                    $code < 0x10000 => \chr(0xE0 | $code >> 12).\chr(0x80 | $code >> 6 & 0x3F).\chr(0x80 | $code & 0x3F),
                    default => \chr(0xF0 | $code >> 18).\chr(0x80 | $code >> 12 & 0x3F).\chr(0x80 | $code >> 6 & 0x3F).\chr(0x80 | $code & 0x3F),
                };
            },
            $info,
        ) ?? $info;
    }

    private function escape(string $text): string
    {
        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $text);
    }
}
