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

namespace Alto\Markdown\Extension\CommonMark;

use Alto\Markdown\Extension\AbstractExtension;
use Alto\Markdown\Parser\Block\AtxHeadingParser;
use Alto\Markdown\Parser\Block\BlockQuoteParser;
use Alto\Markdown\Parser\Block\FencedCodeParser;
use Alto\Markdown\Parser\Block\HtmlBlockParser;
use Alto\Markdown\Parser\Block\IndentedCodeParser;
use Alto\Markdown\Parser\Block\LinkReferenceDefinitionParser;
use Alto\Markdown\Parser\Block\ListItemParser;
use Alto\Markdown\Parser\Block\ListParser;
use Alto\Markdown\Parser\Block\SetextHeadingParser;
use Alto\Markdown\Parser\Block\ThematicBreakParser;
use Alto\Markdown\Parser\Inline\AutolinkParser;
use Alto\Markdown\Parser\Inline\BackslashEscapeParser;
use Alto\Markdown\Parser\Inline\CodeSpanParser;
use Alto\Markdown\Parser\Inline\EntityReferenceParser;
use Alto\Markdown\Parser\Inline\RawHtmlParser;
use Alto\Markdown\Profile\Feature;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class CoreExtension extends AbstractExtension
{
    public function __construct()
    {
        parent::__construct('core', [Feature::CommonMark]);
    }

    public function blockConstructs(): array
    {
        return [
            new BlockQuoteParser(),
            new AtxHeadingParser(),
            new FencedCodeParser(),
            new HtmlBlockParser(),
            new SetextHeadingParser(),
            new ThematicBreakParser(),
            new ListParser(),
            new ListItemParser(),
            new IndentedCodeParser(),
            new LinkReferenceDefinitionParser(),
        ];
    }

    public function inlineConstructs(): array
    {
        return [
            new BackslashEscapeParser(),
            new EntityReferenceParser(),
            new CodeSpanParser(),
            new AutolinkParser(),
            new RawHtmlParser(),
        ];
    }
}
