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

namespace Alto\Markdown\Extension\Footnote;

use Alto\Markdown\Extension\Document\DocumentHtmlFinalizer;
use Alto\Markdown\Extension\Document\DocumentRenderProjection;
use Alto\Markdown\Parser\ReferenceMap;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class FootnoteCatalog implements DocumentRenderProjection, DocumentHtmlFinalizer
{
    /**
     * @var array<string, string>
     */
    private array $definitionHtml = [];

    /**
     * @var array<string, array{string, string}>
     */
    private array $referenceMarkers = [];

    public function recordHtml(string $label, string $html): void
    {
        $key = ReferenceMap::normalize($label);
        if ('' !== $key && !isset($this->definitionHtml[$key])) {
            $this->definitionHtml[$key] = $html;
        }
    }

    public function referenceMarker(string $label, string $fallback): string
    {
        $key = ReferenceMap::normalize($label);
        $marker = "\x1Ealto-footnote-".spl_object_id($this).'-'.\count($this->referenceMarkers)."\x1F";
        $this->referenceMarkers[$marker] = [$key, $fallback];

        return $marker;
    }

    public function finalizeHtml(string $html): string
    {
        $numbers = [];
        $occurrences = [];
        $order = [];
        $replacements = [];

        foreach ($this->referenceMarkers as $marker => [$key, $fallback]) {
            if (!isset($this->definitionHtml[$key])) {
                $replacements[$marker] = $fallback;

                continue;
            }

            if (!isset($numbers[$key])) {
                $numbers[$key] = \count($order) + 1;
                $order[] = $key;
            }

            $number = $numbers[$key];
            $occurrence = ($occurrences[$key] ?? 0) + 1;
            $occurrences[$key] = $occurrence;
            $suffix = 1 === $occurrence ? '' : '-'.$occurrence;
            $replacements[$marker] = '<sup id="fnref-'.$number.$suffix.'">'
                .'<a href="#fn-'.$number.'" role="doc-noteref">'
                .$number
                .'</a></sup>';
        }

        $items = '';

        foreach ($order as $key) {
            $body = $this->definitionHtml[$key];
            $number = $numbers[$key];
            $backlinks = '';

            for ($occurrence = 1; $occurrence <= $occurrences[$key]; ++$occurrence) {
                $suffix = 1 === $occurrence ? '' : '-'.$occurrence;
                $backlinks .= ' <a href="#fnref-'.$number.$suffix.'" role="doc-backlink">↩</a>';
            }

            $body = rtrim($body, "\n");
            if (str_ends_with($body, '</p>')) {
                $body = substr($body, 0, -4).$backlinks.'</p>';
            } else {
                $body .= $backlinks;
            }

            $items .= '<li id="fn-'.$number.'" role="doc-endnote">'."\n"
                .$body."\n"
                ."</li>\n";
        }

        if ('' !== $items) {
            $html .= "<div class=\"footnotes\" role=\"doc-endnotes\">\n"
                ."<hr />\n<ol>\n"
                .$items
                ."</ol>\n</div>\n";
        }

        return strtr($html, $replacements);
    }
}
