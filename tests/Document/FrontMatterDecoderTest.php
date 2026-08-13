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

namespace Alto\Markdown\Tests\Document;

use Alto\Markdown\Extension\FrontMatter\CallbackFrontMatterDecoder;
use Alto\Markdown\Extension\FrontMatter\FrontMatterDecoder;
use Alto\Markdown\Markdown;
use PHPUnit\Framework\TestCase;

final class FrontMatterDecoderTest extends TestCase
{
    public function testExplicitDecoderReceivesOpaqueContentAndFence(): void
    {
        $document = Markdown::github()->fromString("---\ntitle: Guide\n---\n# Guide\n");
        $frontMatter = $document->frontMatter();
        self::assertNotNull($frontMatter);

        $decoded = $frontMatter->decode(new CallbackFrontMatterDecoder(
            static fn(string $content, string $fence): array => [
                'content' => $content,
                'fence' => $fence,
            ],
        ));

        self::assertSame([
            'content' => "title: Guide\n",
            'fence' => '---',
        ], $decoded);
        self::assertSame("---\ntitle: Guide\n---\n# Guide\n", $document->toMarkdown());
    }

    public function testDecoderReadsTheCurrentEditedContent(): void
    {
        $document = Markdown::github()->fromString("+++\ntitle = \"Old\"\n+++\n");
        $frontMatter = $document->frontMatter();
        self::assertNotNull($frontMatter);

        $frontMatter = $frontMatter->replaceContent('title = "New"');

        self::assertSame(
            ['+++', "title = \"New\"\n"],
            $frontMatter->decode(new CallbackFrontMatterDecoder(
                static fn(string $content, string $fence): array => [$fence, $content],
            )),
        );
    }

    public function testApplicationDecoderMayUseADedicatedClass(): void
    {
        $document = Markdown::github()->fromString("---\n{\"title\":\"Guide\"}\n---\n");
        $frontMatter = $document->frontMatter();
        self::assertNotNull($frontMatter);

        self::assertSame(
            ['title' => 'Guide'],
            $frontMatter->decode(new JsonObjectFrontMatterDecoder()),
        );
    }

    public function testDecoderFailurePropagatesWithoutChangingTheDocument(): void
    {
        $source = "---\ninvalid\n---\nBody.\n";
        $document = Markdown::github()->fromString($source);
        $frontMatter = $document->frontMatter();
        self::assertNotNull($frontMatter);

        $caught = null;

        try {
            $frontMatter->decode(new CallbackFrontMatterDecoder(
                static fn(): never => throw new \DomainException('Invalid metadata.'),
            ));
        } catch (\DomainException $exception) {
            $caught = $exception;
        }

        self::assertSame('Invalid metadata.', $caught->getMessage());
        self::assertSame($source, $document->toMarkdown());
        self::assertFalse($document->hasChanges());
    }
}

/**
 * @implements FrontMatterDecoder<array<string, mixed>>
 */
final readonly class JsonObjectFrontMatterDecoder implements FrontMatterDecoder
{
    public function decode(string $content, string $fence): array
    {
        if ('---' !== $fence) {
            throw new \DomainException('Expected a YAML-compatible fence.');
        }

        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new \DomainException('Expected an object.');
        }

        $result = [];

        foreach ($decoded as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
