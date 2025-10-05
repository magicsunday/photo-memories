<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Http\Response;

use RuntimeException;

use function array_map;
use function explode;
use function header;
use function http_response_code;
use function implode;
use function is_string;
use function readfile;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * Simple HTTP response abstraction.
 */
class Response
{
    /** @var array<string,string> */
    private array $headers;

    public function __construct(
        private string $content = '',
        private int $statusCode = 200,
        array $headers = [],
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $normalized[$this->normalizeHeaderName($name)] = $value;
        }

        $this->headers = $normalized;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    /**
     * @return array<string,string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setHeader(string $name, string $value): void
    {
        $this->headers[$this->normalizeHeaderName($name)] = $value;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true, $this->statusCode);
        }

        echo $this->content;
    }

    protected function sendFile(string $path): void
    {
        $result = readfile($path);
        if ($result === false) {
            throw new RuntimeException('Unable to stream binary file.');
        }
    }

    private function normalizeHeaderName(string $name): string
    {
        $parts = array_map(
            static fn (string $part): string => strtoupper($part[0]) . strtolower(substr($part, 1)),
            explode('-', $name),
        );

        return implode('-', $parts);
    }
}
