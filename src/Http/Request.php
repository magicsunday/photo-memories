<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Http;

use function array_change_key_case;
use function array_key_exists;
use function explode;
use function file_get_contents;
use function is_string;
use function parse_url;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

use const CASE_LOWER;
use const PHP_URL_PATH;

/**
 * Minimal HTTP request abstraction for the built-in micro framework.
 */
final class Request
{
    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers
     * @param array<string,string> $server
     */
    private function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $headers,
        private array $server,
        private ?string $body,
    ) {
    }

    public static function fromGlobals(): self
    {
        $server  = [];
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $server[$key] = $value;

            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name             = str_replace('_', '-', substr($key, 5));
            $headers[$name]   = $value;
        }

        $query = [];
        foreach ($_GET as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $query[$key] = $value;
        }

        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $uri    = $server['REQUEST_URI'] ?? '/';
        $path   = (string) parse_url($uri, PHP_URL_PATH);

        $headers = array_change_key_case($headers);

        $body = null;
        if (isset($server['CONTENT_LENGTH']) && $server['CONTENT_LENGTH'] !== '0') {
            $stream = file_get_contents('php://input');
            $body   = $stream !== false && $stream !== '' ? $stream : null;
        }

        return new self($method, $path !== '' ? $path : '/', $query, $headers, $server, $body);
    }

    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers
     * @param array<string,string> $server
     */
    public static function create(
        string $path,
        string $method = 'GET',
        array $query = [],
        array $headers = [],
        array $server = [],
        ?string $body = null,
    ): self {
        $normalizedQuery = [];
        foreach ($query as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $normalizedQuery[$key] = $value;
        }

        $normalizedHeaders = [];
        foreach ($headers as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            $normalizedHeaders[strtolower($key)] = trim($value);
        }

        $serverData = [
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI'    => $path,
            'SERVER_NAME'    => $server['SERVER_NAME'] ?? 'localhost',
        ];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            $serverData[$key] = $value;
        }

        $normalizedPath = $path !== '' ? $path : '/';

        return new self(strtoupper($method), $normalizedPath, $normalizedQuery, $normalizedHeaders, $serverData, $body);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQueryParam(string $name, ?string $default = null): ?string
    {
        if (!array_key_exists($name, $this->query)) {
            return $default;
        }

        $value = $this->query[$name];

        if ($value === '') {
            return $default;
        }

        return $value;
    }

    /**
     * @return array<string,string>
     */
    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function getHeader(string $name): ?string
    {
        $normalized = strtolower($name);

        if (!array_key_exists($normalized, $this->headers)) {
            return null;
        }

        $value = trim($this->headers[$normalized]);

        if ($value === '') {
            return null;
        }

        return $value;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getHost(): string
    {
        $hostHeader = $this->getHeader('host');
        if ($hostHeader !== null) {
            $parts = explode(':', $hostHeader);

            return $parts[0];
        }

        $serverName = $this->server['SERVER_NAME'] ?? null;
        if (is_string($serverName) && $serverName !== '') {
            return $serverName;
        }

        return 'localhost';
    }

    public function getScheme(): string
    {
        $https = $this->server['HTTPS'] ?? '';
        if ($https === 'on' || $https === '1') {
            return 'https';
        }

        return 'http';
    }

    public function getBaseUrl(): string
    {
        $scheme = $this->getScheme();
        $host   = $this->getHost();

        return $scheme . '://' . $host;
    }
}
