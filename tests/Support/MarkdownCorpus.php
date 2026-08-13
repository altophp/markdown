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

use Alto\Markdown\Tests\Conformance\SpecExample;
use Alto\Markdown\Tests\Conformance\SpecExampleLoader;

final class MarkdownCorpus
{
    public const int COMMONMARK_EXAMPLES = 652;
    public const int GFM_EXAMPLES = 672;
    public const int EXTERNAL_READMES = 3;
    public const int DIVERGENCE_SURVIVORS = 45;
    public const int LARGE_DOCUMENTS = 1;
    public const int GENERATED_DOCUMENTS = 7;
    public const int REAL_AND_GENERATED_DOCUMENTS = 56;
    public const int SEMANTIC_DOCUMENTS = 55;
    public const int CONFORMING_DOCUMENTS = 6;

    /**
     * @return list<SpecExample>
     */
    public static function commonMarkExamples(): array
    {
        return self::examples('spec_tests.json', self::COMMONMARK_EXAMPLES);
    }

    /**
     * @return list<SpecExample>
     */
    public static function gfmExamples(): array
    {
        return self::examples('gfm_tests.json', self::GFM_EXAMPLES);
    }

    /**
     * @return array<string, string>
     */
    public static function realAndGeneratedDocuments(): array
    {
        $documents = [
            'readme-laravel' => self::fixture('readme-laravel.md'),
            'readme-react' => self::fixture('readme-react.md'),
            'readme-symfony' => self::fixture('readme-symfony.md'),
        ];

        $divergences = glob(self::fixturePath('divergences/case-*.md'));

        if (!\is_array($divergences)) {
            throw new \RuntimeException('Unable to discover divergence corpus.');
        }

        sort($divergences, \SORT_STRING);

        foreach ($divergences as $path) {
            $documents['divergence-' . pathinfo($path, \PATHINFO_FILENAME)] = self::read($path);
        }

        $documents['huge-generated-corpus'] = self::fixture('huge.md');

        foreach (self::generatedDocuments() as $name => $bytes) {
            $documents['generated-' . $name] = $bytes;
        }

        if (self::REAL_AND_GENERATED_DOCUMENTS !== \count($documents)) {
            throw new \RuntimeException(\sprintf('Expected %d real and generated Markdown documents, found %d.', self::REAL_AND_GENERATED_DOCUMENTS, \count($documents)));
        }

        return $documents;
    }

    /**
     * @return array<string, string>
     */
    public static function conformingDocuments(): array
    {
        $documents = [
            'lf-basic' => "# Title\n\nParagraph.\n",
            'crlf-basic' => "# Title\r\n\r\nParagraph.\r\n",
            'bom-basic' => "\xEF\xBB\xBF# Title\n\nParagraph.\n",
            'code-payload-spaces' => "```text\nalpha  \nbeta   \n```\n",
            'nested-containers' => "> # Quote\n>\n> - one\n>   - nested\n",
            'gfm-table-and-task' => "# Work\n\n- [x] done\n\n| a | b |\n| --- | --- |\n| c | d |\n",
        ];

        if (self::CONFORMING_DOCUMENTS !== \count($documents)) {
            throw new \RuntimeException('Conforming corpus count drifted.');
        }

        return $documents;
    }

    /**
     * @return array<string, string>
     */
    private static function generatedDocuments(): array
    {
        $documents = [
            'lf-style-violations' => "#  Title\n\n\n+ one   \n  * nested\n\n~~~php\necho 'ok';\n~~~",
            'crlf-style-violations' => "#\tTitle\r\n\r\n \t\r\n\r\n* item   \r\n",
            'bom-style-violations' => "\xEF\xBB\xBF#  Title\n\n+ item\n",
            'code-payload' => "~~~text\nalpha  \nbeta   \n```\n~~~\n",
            'raw-html' => "<pre>\nalpha   \n</pre>\n\nParagraph.\n",
            'nested-containers' => "> ##\tQuote\n>\n> + parent\n>   * child\n",
            'fixable-mixed' => "# Title\n\nVisit https://example.com   \n\n```\necho 'ok';\n```",
        ];

        if (self::GENERATED_DOCUMENTS !== \count($documents)) {
            throw new \RuntimeException('Generated corpus count drifted.');
        }

        return $documents;
    }

    /**
     * @return list<SpecExample>
     */
    private static function examples(string $fixture, int $expected): array
    {
        $examples = (new SpecExampleLoader())->load(self::fixture($fixture));

        if ($expected !== \count($examples)) {
            throw new \RuntimeException(\sprintf('Expected %d examples in %s, found %d.', $expected, $fixture, \count($examples)));
        }

        return $examples;
    }

    private static function fixture(string $relativePath): string
    {
        return self::read(self::fixturePath($relativePath));
    }

    private static function fixturePath(string $relativePath): string
    {
        return \dirname(__DIR__) . '/fixtures/' . $relativePath;
    }

    private static function read(string $path): string
    {
        $bytes = file_get_contents($path);

        if (false === $bytes) {
            throw new \RuntimeException(\sprintf('Unable to read Markdown corpus file "%s".', $path));
        }

        return $bytes;
    }
}
