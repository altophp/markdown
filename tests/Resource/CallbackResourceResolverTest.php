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

namespace Alto\Markdown\Tests\Resource;

use Alto\Markdown\Exception\InvalidMarkdownArgumentException;
use Alto\Markdown\Resource\CallbackResourceResolver;
use Alto\Markdown\Resource\ResolvedResource;
use Alto\Markdown\Resource\ResourceRequest;
use PHPUnit\Framework\TestCase;

final class CallbackResourceResolverTest extends TestCase
{
    public function testCallbackReceivesTheCompleteRequestAndReturnsTypedBytes(): void
    {
        $seen = null;
        $resolver = new CallbackResourceResolver(
            static function (ResourceRequest $request) use (&$seen): ResolvedResource {
                $seen = $request;

                return new ResolvedResource('memory:guide', "# Guide\n");
            },
        );
        $request = new ResourceRequest('guide.md', 'import', 'memory:index');

        $resource = $resolver->resolve($request);

        self::assertSame($request, $seen);
        self::assertSame('memory:guide', $resource->id);
        self::assertSame("# Guide\n", $resource->bytes);
    }

    public function testCallbackExceptionsRemainApplicationControlled(): void
    {
        $resolver = new CallbackResourceResolver(
            static fn (): never => throw new \RuntimeException('backend unavailable'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('backend unavailable');

        $resolver->resolve(new ResourceRequest('guide.md', 'import'));
    }

    public function testCallbackMustReturnAResolvedResource(): void
    {
        $resolver = new CallbackResourceResolver(static fn (): string => 'invalid');

        $this->expectException(InvalidMarkdownArgumentException::class);
        $this->expectExceptionMessage('must return ResolvedResource');

        $resolver->resolve(new ResourceRequest('guide.md', 'import'));
    }

    public function testResolvedResourceRejectsAnInvalidOpaqueId(): void
    {
        foreach (['', "bad\x00id"] as $id) {
            try {
                new ResolvedResource($id, '');
                self::fail('Invalid resource ID must fail.');
            } catch (InvalidMarkdownArgumentException $exception) {
                self::assertStringContainsString('resource ID', $exception->getMessage());
            }
        }
    }
}
