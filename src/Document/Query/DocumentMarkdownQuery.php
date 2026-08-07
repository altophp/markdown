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

namespace Alto\Markdown\Document\Query;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Node\NodeHandle;
use Alto\Markdown\Query\Collection;
use Alto\Markdown\Query\MarkdownQuery;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class DocumentMarkdownQuery implements MarkdownQuery
{
    /**
     * @param list<int>                        $kindIds
     * @param list<callable(NodeHandle): bool> $predicates
     */
    public function __construct(
        private ParsedDocumentModel $model,
        private array $kindIds = [],
        private bool $filterByKind = false,
        private array $predicates = [],
    ) {
    }

    public function kind(string $kind): self
    {
        $resolved = $this->model->findNodeKind($kind);
        $kindIds = null === $resolved ? $this->kindIds : [...$this->kindIds, $resolved->id];

        return new self($this->model, $kindIds, true, $this->predicates);
    }

    public function where(callable $predicate): self
    {
        return new self($this->model, $this->kindIds, $this->filterByKind, [...$this->predicates, $predicate]);
    }

    public function get(): Collection
    {
        /** @var LazyCollection<NodeHandle> $collection */
        $collection = new LazyCollection(function (): iterable {
            foreach ($this->model->queryHandlesByKindIds($this->kindIds, $this->filterByKind) as $handle) {
                foreach ($this->predicates as $predicate) {
                    if (!$predicate($handle)) {
                        continue 2;
                    }
                }

                yield $handle;
            }
        });

        return $collection;
    }
}
