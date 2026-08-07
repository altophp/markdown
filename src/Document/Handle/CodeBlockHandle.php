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

namespace Alto\Markdown\Document\Handle;

use Alto\Markdown\Document\ParsedDocumentModel;
use Alto\Markdown\Node\CodeBlock;
use Alto\Markdown\Node\Id\NodeId;
use Alto\Markdown\Operation\ReplaceCodeBlockContentOperation;
use Alto\Markdown\Operation\SetCodeBlockLanguageOperation;
use Alto\Markdown\Parser\Instrumentation;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final class CodeBlockHandle extends BlockNodeHandle implements CodeBlock
{
    public function __construct(ParsedDocumentModel $model, NodeId $id)
    {
        ++Instrumentation::$codeBlockHandles;

        parent::__construct($model, $id);
    }

    public function language(): ?string
    {
        $this->assertExists();

        return $this->model->codeBlockLanguage($this->id()->ordinal);
    }

    public function code(): string
    {
        $this->assertExists();

        return $this->model->codeBlockCode($this->id()->ordinal);
    }

    public function setLanguage(string $language): self
    {
        $this->assertExists();
        SetCodeBlockLanguageOperation::validateLanguage($language);

        if ($language === $this->language()) {
            return $this;
        }

        $range = $this->model->codeBlockLanguageRange($this->id());
        $operation = new SetCodeBlockLanguageOperation($this->id(), $language, $range);
        $operation->apply($this->model);
        $this->model->journal()->record($operation, $range);

        return new self($this->model, $this->model->currentNodeId($this->id()->ordinal));
    }

    public function replaceCode(string $code): self
    {
        $this->assertExists();
        $range = $this->model->codeBlockPatchRange($this->id());
        $operation = new ReplaceCodeBlockContentOperation($this->id(), $this->language(), $code, $range);
        $operation->apply($this->model);
        $this->model->journal()->record($operation, $range);

        return new self($this->model, $this->model->currentNodeId($this->id()->ordinal));
    }
}
