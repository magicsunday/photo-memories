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
    /**
     * Creates a new request instance with the given normalized data.
     *
     * @param array<string,string> $query   normalized query parameters
     * @param array<string,string> $headers lowercase header map including the HTTP_* values
     * @param array<string,string> $server  server parameter map as provided by PHP
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

    /**
     * Builds a request instance from the PHP global state.
     *
     * Iterates over $_SERVER, $_GET and php://input to create an immutable
     * representation of the incoming HTTP request. All values are normalized
     * to strings to avoid dealing with mixed input types.
     *
     * @return self immutable request reflecting the superglobals
     */
    public static function fromGlobals(): self
    {
        $server  = [];
        $headers = [];

        // Normalise server parameters and extract HTTP headers from the
        // corresponding HTTP_* entries provided by PHP.
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

            $name           = str_replace('_', '-', substr($key, 5));
            $headers[$name] = $value;
        }

        $query = [];
        // Normalize the query string parameters to ensure consistent string keys
        // and values, ignoring anything that cannot be represented as a string.
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
     * Creates a synthetic request for testing purposes.
     *
     * @param array<string,string> $query   request query parameters
     * @param array<string,string> $headers HTTP headers
     * @param array<string,string> $server  additional server parameters
     *
     * @return self newly created request instance
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

    /**
     * Returns the HTTP method used for the request (e.g. GET or POST).
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Returns the normalized request path without scheme or host.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Returns a single query parameter or the provided default if unavailable.
     *
     * @param string      $name    parameter key to fetch
     * @param string|null $default fallback value when the parameter is missing
     */
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

    /**
     * Returns a header value using a case-insensitive lookup.
     *
     * @return string|null normalized header value or null when absent
     */
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

    /**
     * Returns the raw request body if one was provided.
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Resolves the request host either from the Host header or SERVER_NAME.
     */
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

    /**
     * Returns the request scheme (http or https) based on the HTTPS flag.
     */
    public function getScheme(): string
    {
        $https = $this->server['HTTPS'] ?? '';
        if ($https === 'on' || $https === '1') {
            return 'https';
        }

        return 'http';
    }

    /**
     * Builds the base URL consisting of the scheme and host.
     */
    public function getBaseUrl(): string
    {
        $scheme = $this->getScheme();
        $host   = $this->getHost();

        return $scheme . '://' . $host;
    }
}
