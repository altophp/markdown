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

namespace Alto\Markdown\Tests\Profile;

use Alto\Markdown\Profile\CommonMarkProfile;
use Alto\Markdown\Profile\GfmProfile;
use Alto\Markdown\Profile\ProfileCompiler;
use PHPUnit\Framework\TestCase;

final class CompiledProfilePlainTextTest extends TestCase
{
    public function testCommonmarkPlainSetHasNoAlnumNoTildeAndNoContentTriggers(): void
    {
        $p = new ProfileCompiler()->compile(new CommonMarkProfile());

        // every byte of the plain set is a genuine per-byte inline trigger
        self::assertSame('', $this->alnum($p->plainTextSpecialBytes));
        self::assertStringNotContainsString('~', $p->plainTextSpecialBytes);
        foreach (["\n", '*', '_', '[', ']', '!', '\\', '&', '`', '<'] as $b) {
            self::assertStringContainsString($b, $p->plainTextSpecialBytes);
        }
        self::assertSame([], $p->contentScanTriggers);
    }

    public function testGfmPlainSetAddsTildeButStillNoAlnumAndDeclaresAutolinkTriggers(): void
    {
        $p = new ProfileCompiler()->compile(new GfmProfile());

        self::assertSame('', $this->alnum($p->plainTextSpecialBytes));
        self::assertStringContainsString('~', $p->plainTextSpecialBytes);
        self::assertSame(['www.', '://', '@'], $p->contentScanTriggers);
    }

    /**
     * The extended autolink over-approximates its start bytes with every
     * ASCII letter and digit, which would stop the scan loop on nearly
     * every byte of prose. It is located by content search instead, so its
     * bytes stay out of both sets and the two sets agree.
     */
    public function testGfmParserSetDropsTheAutolinkOverApproximation(): void
    {
        $p = new ProfileCompiler()->compile(new GfmProfile());

        self::assertSame('', $this->alnum($p->inlineSpecialBytes));
        self::assertSame($p->plainTextSpecialBytes, $p->inlineSpecialBytes);
        self::assertSame("\n*_[]!\\&`<~", $p->inlineSpecialBytes);

        foreach (['.', '-', '+'] as $byte) {
            self::assertStringNotContainsString($byte, $p->inlineSpecialBytes);
        }

        self::assertCount(1, $p->contentScannedConstructs);
    }

    private function alnum(string $bytes): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', $bytes) ?? '';
    }
}
