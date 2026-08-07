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

namespace Alto\Markdown\Tests\Extension;

use Alto\Markdown\Exception\DuplicateExtensionException;
use Alto\Markdown\Extension\CompiledExtension;
use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\FeatureExtensionInterface;
use Alto\Markdown\Markdown;
use Alto\Markdown\Profile\Feature;
use PHPUnit\Framework\TestCase;

final class FactoryCompositionTest extends TestCase
{
    public function testFactoryCompositionPreservesBaseAndExtensionOrder(): void
    {
        $base = Markdown::commonmark();
        $composed = $base->with(
            new MetadataExtension('first'),
            new MetadataExtension('second'),
        );

        self::assertNotSame($base, $composed);
        self::assertSame(['core'], $this->extensionNames($base->profile()->extensions()));
        self::assertSame(['core', 'first', 'second'], $this->extensionNames($composed->profile()->extensions()));
        self::assertSame('commonmark+first+second', $composed->profile()->name());
        self::assertSame($base->profile()->fallbackPolicy(), $composed->profile()->fallbackPolicy());
        self::assertSame($base->profile()->style(), $composed->profile()->style());
        self::assertSame("<h1>Title</h1>\n", $base->toHtml("# Title\n"));
        self::assertSame("<h1>Title</h1>\n", $composed->toHtml("# Title\n"));
    }

    public function testCompositionRejectsADuplicateAdditionalExtension(): void
    {
        $this->expectException(DuplicateExtensionException::class);
        $this->expectExceptionMessage('Extension "example" is already installed.');

        Markdown::commonmark()->with(
            new MetadataExtension('example'),
            new MetadataExtension('example'),
        );
    }

    public function testFurtherCompositionDoesNotMutateTheFirstDerivedFactory(): void
    {
        $first = Markdown::commonmark()->with(new MetadataExtension('first'));
        $second = $first->with(new MetadataExtension('second'));

        self::assertNotSame($first, $second);
        self::assertSame(['core', 'first'], $this->extensionNames($first->profile()->extensions()));
        self::assertSame(['core', 'first', 'second'], $this->extensionNames($second->profile()->extensions()));
        self::assertSame('commonmark+first', $first->profile()->name());
        self::assertSame('commonmark+first+second', $second->profile()->name());
    }

    public function testCompositionRejectsAnExtensionAlreadyInTheBaseProfile(): void
    {
        $this->expectException(DuplicateExtensionException::class);
        $this->expectExceptionMessage('Extension "core" is already installed.');

        Markdown::commonmark()->with(new MetadataExtension('core'));
    }

    public function testComposedProfileReportsFeaturesAcrossExtensionTypes(): void
    {
        $profile = Markdown::commonmark()
            ->with(
                new MetadataExtension('metadata'),
                new FeatureMetadataExtension('tables', Feature::Tables),
            )
            ->profile();

        self::assertTrue($profile->supports(Feature::CommonMark));
        self::assertTrue($profile->supports(Feature::Tables));
        self::assertFalse($profile->supports(Feature::GitHubAlerts));
    }

    public function testCompiledExtensionExposesItsFeatures(): void
    {
        $extension = new CompiledExtension('compiled', [Feature::Tables], []);

        self::assertSame('compiled', $extension->name());
        self::assertSame([Feature::Tables], iterator_to_array($extension->features()));
        self::assertSame([], iterator_to_array($extension->nodeKinds()));
    }

    /**
     * @param iterable<ExtensionInterface> $extensions
     *
     * @return list<string>
     */
    private function extensionNames(iterable $extensions): array
    {
        $names = [];

        foreach ($extensions as $extension) {
            $names[] = $extension->name();
        }

        return $names;
    }
}

final readonly class MetadataExtension implements ExtensionInterface
{
    public function __construct(private string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }
}

final readonly class FeatureMetadataExtension implements FeatureExtensionInterface
{
    public function __construct(
        private string $name,
        private Feature $feature,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function features(): iterable
    {
        yield $this->feature;
    }
}
