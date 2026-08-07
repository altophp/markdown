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

namespace Alto\Markdown\Extension\Gfm;

use Alto\Markdown\Extension\AbstractExtension;
use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\NodeKindBindings;
use Alto\Markdown\Node\Kind\NodeKind;
use Alto\Markdown\Parser\Block\GfmTableParser;
use Alto\Markdown\Parser\Inline\ExtendedAutolinkParser;
use Alto\Markdown\Profile\Feature;

/**
 * Published GitHub Flavored Markdown extension set only. Platform-specific
 * GitHub behavior belongs to GitHubAlertsExtension or later github-only
 * extensions, not here.
 *
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class GfmExtension extends AbstractExtension
{
    public const string TABLE_KIND = 'gfm:table';

    /**
     * @param list<NodeKind> $nodeKinds
     */
    public function __construct(
        private readonly ?NodeKind $tableKind = null,
        array $nodeKinds = [],
    ) {
        parent::__construct(
            'gfm',
            [
                Feature::Tables,
                Feature::TaskLists,
                Feature::Strikethrough,
                Feature::ExtendedAutolinks,
                Feature::TagFilter,
            ],
            [
                'table',
            ],
            $nodeKinds,
        );
    }

    public function blockConstructs(): array
    {
        return null === $this->tableKind ? [] : [new GfmTableParser($this->tableKind->id)];
    }

    public function inlineConstructs(): array
    {
        return [new ExtendedAutolinkParser()];
    }

    public function bindNodeKinds(NodeKindBindings $bindings): ExtensionInterface
    {
        return new self(
            $bindings->get(self::TABLE_KIND),
            $bindings->all(),
        );
    }
}
