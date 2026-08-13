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

namespace Alto\Markdown\Extension\Embed;

use Alto\Markdown\Exception\InvalidExtensionException;

/**
 * @author Simon André <smn.andre@gmail.com>
 */
final readonly class EmbedPolicy
{
    /**
     * @var list<string>
     */
    public array $allowedHosts;

    /**
     * @param list<string> $allowedHosts
     */
    public function __construct(
        array $allowedHosts,
        public bool $includeSubdomains = false,
        public bool $allowHttp = false,
        public EmbedFallback $fallback = EmbedFallback::Link,
        public int $maxUrlBytes = 2_048,
        public int $maxHtmlBytes = 262_144,
    ) {
        if ([] === $allowedHosts) {
            throw new InvalidExtensionException('Embed allowed hosts must not be empty.');
        }
        if ($maxUrlBytes < 1 || \PHP_INT_MAX === $maxUrlBytes) {
            throw new InvalidExtensionException('Embed URL limit must be between 1 and PHP_INT_MAX - 1.');
        }
        if ($maxHtmlBytes < 1 || \PHP_INT_MAX === $maxHtmlBytes) {
            throw new InvalidExtensionException('Embed HTML limit must be between 1 and PHP_INT_MAX - 1.');
        }

        $normalized = [];

        foreach ($allowedHosts as $host) {
            if (!\is_string($host)) {
                throw new InvalidExtensionException('Embed allowed hosts must be strings.');
            }

            $host = self::normalizeHost($host);
            if ('' === $host || !self::isHost($host)) {
                throw new InvalidExtensionException(\sprintf('Embed allowed host "%s" is invalid.', $host));
            }

            $normalized[$host] = true;
        }

        $this->allowedHosts = array_keys($normalized);
    }

    public function isValidUrl(string $url): bool
    {
        return null !== $this->urlParts($url);
    }

    public function allowsUrl(string $url): bool
    {
        $parts = $this->urlParts($url);
        if (null === $parts) {
            return false;
        }

        $scheme = $parts['scheme'];
        if ('https' !== $scheme && !('http' === $scheme && $this->allowHttp)) {
            return false;
        }

        $defaultPort = 'https' === $scheme ? 443 : 80;
        if (null !== $parts['port'] && $defaultPort !== $parts['port']) {
            return false;
        }

        $host = $parts['host'];

        foreach ($this->allowedHosts as $allowed) {
            if (
                $host === $allowed
                || (
                    $this->includeSubdomains
                    && false === filter_var($allowed, \FILTER_VALIDATE_IP)
                    && str_ends_with($host, '.' . $allowed)
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{scheme: string, host: string, port: int|null}|null
     */
    private function urlParts(string $url): ?array
    {
        if (
            '' === $url
            || \strlen($url) > $this->maxUrlBytes
            || 1 === preg_match('/[\x00-\x20\x7f]/', $url)
        ) {
            return null;
        }

        $parts = parse_url($url);
        if (
            !\is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !\is_string($parts['scheme'])
            || !\is_string($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ('https' !== $scheme && 'http' !== $scheme) {
            return null;
        }

        $host = self::normalizeHost($parts['host']);
        if ('' === $host || !self::isHost($host)) {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $parts['port'] ?? null];
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
            . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
            $host,
        );
    }
}
