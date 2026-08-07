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

use Alto\Markdown\Extension\AbstractExtension;
use Alto\Markdown\Extension\Block\BlockDefinition;
use Alto\Markdown\Extension\BlockExtensionInterface;
use Alto\Markdown\Extension\Document\DocumentTransformDefinition;
use Alto\Markdown\Extension\DocumentTransformExtensionInterface;
use Alto\Markdown\Extension\ExtensionInterface;
use Alto\Markdown\Extension\NativeInlineOutputExtensionInterface;
use Alto\Markdown\Extension\NodeKindBindings;
use Alto\Markdown\Node\Kind\NodeKind;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final class FootnoteExtension extends AbstractExtension implements BlockExtensionInterface, DocumentTransformExtensionInterface, NativeInlineOutputExtensionInterface
{
    public const string DEFINITION_KIND = 'footnote:definition';
    public const string REFERENCE_KIND = 'footnote:reference';

    /**
     * @param list<NodeKind> $nodeKinds
     */
    public function __construct(
        private readonly ?NodeKind $referenceKind = null,
        array $nodeKinds = [],
    ) {
        parent::__construct('footnote', nodeKindNames: ['reference'], nodeKinds: $nodeKinds);
    }

    public function blocks(): iterable
    {
        $output = new FootnoteDefinitionOutput();

        yield new BlockDefinition(
            kind: 'definition',
            parser: new FootnoteDefinitionParser(),
            html: $output,
            markdown: $output,
        );
    }

    public function documentTransforms(): iterable
    {
        yield new DocumentTransformDefinition(
            'catalog',
            static fn (): FootnoteTransform => new FootnoteTransform(),
        );
    }

    public function blockConstructs(): array
    {
        return [];
    }

    public function inlineConstructs(): array
    {
        return null === $this->referenceKind ? [] : [new FootnoteReferenceParser($this->referenceKind->id)];
    }

    public function bindNodeKinds(NodeKindBindings $bindings): ExtensionInterface
    {
        return new self(
            $bindings->get(self::REFERENCE_KIND),
            $bindings->all(),
        );
    }

    public function nativeHtmlInlineRenderers(): array
    {
        return null === $this->referenceKind
            ? []
            : [$this->referenceKind->id => new FootnoteReferenceOutput()];
    }

    public function nativeMarkdownInlinePrinters(): array
    {
        return null === $this->referenceKind
            ? []
            : [$this->referenceKind->id => new FootnoteReferenceOutput()];
    }
}
