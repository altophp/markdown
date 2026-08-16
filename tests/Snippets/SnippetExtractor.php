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

namespace Alto\Markdown\Tests\Snippets;

/**
 * Pulls fenced PHP code blocks out of a Markdown string.
 *
 * Recognises backtick and tilde fences of length three or more whose opening
 * line is indented up to three spaces and whose info string names PHP. Content
 * is dedented by the opening fence indent, matching CommonMark fenced code.
 */
final class SnippetExtractor
{
    /**
     * @return list<Snippet>
     */
    public function extract(string $markdown): array
    {
        $normalized = str_replace("\r\n", "\n", $markdown);
        $lines = explode("\n", $normalized);

        // A trailing newline terminates the last line; drop the empty element
        // it leaves behind so an unclosed block does not gain a blank line.
        if (str_ends_with($normalized, "\n")) {
            array_pop($lines);
        }

        $snippets = [];
        $open = null;
        $ignored = null;
        $body = [];
        $openLine = 0;

        foreach ($lines as $index => $rawLine) {
            $line = rtrim($rawLine, "\r");

            if (null !== $ignored) {
                if ($this->matchesClosingFence($line, $ignored)) {
                    $ignored = null;
                }

                continue;
            }

            if (null === $open) {
                $isPhp = false;
                $fence = $this->matchOpeningFence($line, $isPhp);

                if (null !== $fence) {
                    if (!$isPhp) {
                        $ignored = $fence;

                        continue;
                    }

                    $open = $fence;
                    $body = [];
                    $openLine = $index + 1;
                }

                continue;
            }

            if ($this->matchesClosingFence($line, $open)) {
                $snippets[] = new Snippet($openLine, $this->join($body));
                $open = null;

                continue;
            }

            $body[] = $this->dedent($line, $open->indent);
        }

        if (null !== $open) {
            $snippets[] = new Snippet($openLine, $this->join($body));
        }

        return $snippets;
    }

    private function matchOpeningFence(string $line, bool &$isPhp): ?OpeningFence
    {
        $isPhp = false;

        if (1 !== preg_match('/^( {0,3})(`{3,}|~{3,})[ \t]*(.*)$/', $line, $matches)) {
            return null;
        }

        $marker = $matches[2];
        $char = $marker[0];
        $info = trim($matches[3]);

        // A backtick info string may not itself contain a backtick.
        if ('`' === $char && str_contains($info, '`')) {
            return null;
        }

        $isPhp = 1 === preg_match('/^php(?![a-zA-Z0-9_])/', $info);

        return new OpeningFence($char, \strlen($marker), \strlen($matches[1]));
    }

    private function matchesClosingFence(string $line, OpeningFence $open): bool
    {
        $pattern = sprintf('/^ {0,3}%1$s{%2$d,}[ \t]*$/', preg_quote($open->char, '/'), $open->length);

        return 1 === preg_match($pattern, $line);
    }

    private function dedent(string $line, int $indent): string
    {
        $removed = 0;
        $offset = 0;
        $length = \strlen($line);

        while ($removed < $indent && $offset < $length && ' ' === $line[$offset]) {
            ++$offset;
            ++$removed;
        }

        return substr($line, $offset);
    }

    /**
     * @param list<string> $body
     */
    private function join(array $body): string
    {
        if ([] === $body) {
            return '';
        }

        return implode("\n", $body) . "\n";
    }
}
