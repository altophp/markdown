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

namespace Alto\Markdown\Parser\Inline;

use Alto\Markdown\Exception\InvalidExtensionException;
use Alto\Markdown\Exception\InvalidInlineResultException;
use Alto\Markdown\Extension\Inline\InlineParser as ExtensionInlineParser;
use Alto\Markdown\Parser\Instrumentation;

/**
 * @internal
 *
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class PublicInlineParserAdapter implements InlineConstruct
{
    private const string ALWAYS_RESERVED_TRIGGERS = "\r\n*_[]!";

    private string $trigger;

    public function __construct(
        private int $kind,
        private ExtensionInlineParser $parser,
        ?string $trigger = null,
    ) {
        $trigger ??= $parser->triggerByte();
        self::validateTrigger($trigger);
        $this->trigger = $trigger;
    }

    public static function validateTrigger(string $trigger, bool $strikethrough = false): void
    {
        if (1 !== \strlen($trigger)) {
            throw new InvalidExtensionException('Custom inline triggerByte() must return exactly one byte.');
        }

        if (str_contains(self::ALWAYS_RESERVED_TRIGGERS, $trigger)
            || ($strikethrough && '~' === $trigger)
        ) {
            throw new InvalidExtensionException(\sprintf('Custom inline trigger byte %s is reserved by the active Markdown profile.', self::describeTrigger($trigger)));
        }
    }

    public function triggerBytes(): string
    {
        return $this->trigger;
    }

    public function tryParse(InlineScanState $state): bool
    {
        ++Instrumentation::$extensionInlineParserAttempts;
        $offset = $state->offset();
        $result = $this->parser->tryParse(new ExtensionInlineContext($state->content(), $offset));

        if (null === $result) {
            return false;
        }

        $length = \strlen($state->content()->text);

        if ($result->endOffset <= $offset || $result->endOffset > $length) {
            throw new InvalidInlineResultException(\sprintf('Custom inline end offset %d must be after cursor %d and no greater than joined content length %d.', $result->endOffset, $offset, $length));
        }

        $state->emitExtension($this->kind, $result->endOffset, $result->node);

        return true;
    }

    private static function describeTrigger(string $trigger): string
    {
        return match ($trigger) {
            "\r" => '"\\r"',
            "\n" => '"\\n"',
            default => '"'.$trigger.'"',
        };
    }
}
