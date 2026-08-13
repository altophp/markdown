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

namespace Alto\Markdown\Node\Kind;

use Alto\Markdown\Exception\UnknownNodeKindException;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class DefaultNodeKindRegistry implements NodeKindRegistry
{
    /**
     * @var array<string, NodeKind>
     */
    private array $byName = [];

    /**
     * @var array<int, NodeKind>
     */
    private array $byId = [];

    private int $nextId = 1;

    public function __construct()
    {
        foreach (self::coreNames() as $name) {
            $this->register($name);
        }
    }

    public function core(string $name): NodeKind
    {
        return $this->byName[$name]
            ?? throw new UnknownNodeKindException(\sprintf('Unknown core node kind "%s".', $name));
    }

    public function reserve(string $extensionName, string $localName): NodeKind
    {
        return $this->register($extensionName . ':' . $localName);
    }

    public function find(string $name): ?NodeKind
    {
        return $this->byName[$name] ?? null;
    }

    public function get(int $id): NodeKind
    {
        return $this->byId[$id]
            ?? throw new UnknownNodeKindException(\sprintf('Unknown node kind id %d.', $id));
    }

    /**
     * @return list<string>
     */
    public static function coreNames(): array
    {
        return [
            'document',
            'paragraph',
            'atx-heading',
            'setext-heading',
            'indented-code',
            'fenced-code',
            'html-block',
            'block-quote',
            'list',
            'list-item',
            'thematic-break',
            'link-reference-definition',
            'inline-root',
            'text',
            'soft-break',
            'hard-break',
            'code-span',
            'emphasis',
            'strong',
            'link',
            'image',
            'autolink',
            'html-inline',
        ];
    }

    private function register(string $name): NodeKind
    {
        if (isset($this->byName[$name])) {
            return $this->byName[$name];
        }

        $kind = new NodeKind($this->nextId, $name);
        ++$this->nextId;

        $this->byName[$name] = $kind;
        $this->byId[$kind->id] = $kind;

        return $kind;
    }
}
