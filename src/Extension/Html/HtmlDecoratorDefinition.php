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

namespace Alto\Markdown\Extension\Html;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class HtmlDecoratorDefinition
{
    private function __construct(
        private ?string $kind,
        public HtmlNodeDecorator $decorator,
        public int $priority = 0,
        private HtmlDecoratorRole $role = HtmlDecoratorRole::Node,
    ) {
    }

    public static function node(string $kind, HtmlNodeDecorator $decorator, int $priority = 0): self
    {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*(?::[a-z][a-z0-9-]*)?$/D', $kind)) {
            throw new InvalidExtensionException(\sprintf('HTML decorator kind "%s" must be a native node kind or a qualified built-in kind.', $kind));
        }

        return new self($kind, $decorator, $priority);
    }

    public static function linkLike(HtmlNodeDecorator $decorator, int $priority = 0): self
    {
        return new self(null, $decorator, $priority, HtmlDecoratorRole::LinkLike);
    }

    public static function heading(HtmlNodeDecorator $decorator, int $priority = 0): self
    {
        return new self(null, $decorator, $priority, HtmlDecoratorRole::Heading);
    }

    public static function block(HtmlNodeDecorator $decorator, int $priority = 0): self
    {
        return new self(null, $decorator, $priority, HtmlDecoratorRole::Block);
    }

    /**
     * @internal
     */
    public function nodeKind(): ?string
    {
        return $this->kind;
    }

    /**
     * @internal
     */
    public function targetsLinkLike(): bool
    {
        return HtmlDecoratorRole::LinkLike === $this->role;
    }

    /**
     * @internal
     */
    public function targetsHeadings(): bool
    {
        return HtmlDecoratorRole::Heading === $this->role;
    }

    /**
     * @internal
     */
    public function targetsBlocks(): bool
    {
        return HtmlDecoratorRole::Block === $this->role;
    }
}
