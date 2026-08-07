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

namespace Alto\Markdown\Extension\ExternalLink;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class ExternalLinkPolicy
{
    /**
     * @var list<string>
     */
    public array $internalHosts;

    /**
     * @param list<string> $internalHosts
     */
    public function __construct(
        array $internalHosts = [],
        public bool $includeSubdomains = false,
        public bool $openInNewWindow = false,
        public string $htmlClass = '',
        public ExternalLinkScope $nofollow = ExternalLinkScope::None,
        public ExternalLinkScope $noopener = ExternalLinkScope::External,
        public ExternalLinkScope $noreferrer = ExternalLinkScope::External,
    ) {
        $normalized = [];

        foreach ($internalHosts as $host) {
            if (!\is_string($host)) {
                throw new InvalidExtensionException('External-link internal hosts must be strings.');
            }

            $host = self::normalizeHost($host);
            if ('' === $host || !self::isHost($host)) {
                throw new InvalidExtensionException(\sprintf('External-link internal host "%s" is invalid.', $host));
            }

            $normalized[$host] = true;
        }

        $this->internalHosts = array_keys($normalized);
    }

    public function isInternalHost(string $host): bool
    {
        $host = self::normalizeHost($host);

        foreach ($this->internalHosts as $internal) {
            if ($host === $internal
                || ($this->includeSubdomains
                    && false === filter_var($internal, \FILTER_VALIDATE_IP)
                    && str_ends_with($host, '.'.$internal))
            ) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));

        return str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
    }

    private static function isHost(string $host): bool
    {
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return true;
        }

        return 1 === preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*'
            .'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
            $host,
        );
    }
}
