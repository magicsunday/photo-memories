<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Http\Response;

use finfo;
use RuntimeException;

use function array_keys;
use function file_get_contents;
use function filesize;
use function is_file;
use function is_string;
use function pathinfo;
use function sprintf;
use function strcasecmp;
use function strtolower;

use const FILEINFO_MIME_TYPE;
use const PATHINFO_EXTENSION;

final class BinaryFileResponse
{
    private const DEFAULT_STATUS = 200;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $filePath,
        private readonly int $status = self::DEFAULT_STATUS,
        private readonly array $headers = [],
    ) {
        if (is_file($filePath) === false) {
            throw new RuntimeException(sprintf('Binary file response path "%s" does not exist.', $filePath));
        }
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function send(): array
    {
        $headers = $this->headers;

        if ($this->hasHeader($headers, 'Content-Type') === false) {
            $headers['Content-Type'] = $this->resolveMimeType($this->filePath);
        }

        if ($this->hasHeader($headers, 'Content-Length') === false) {
            $size = filesize($this->filePath);

            if ($size === false) {
                throw new RuntimeException(sprintf('Unable to determine file size for "%s".', $this->filePath));
            }

            $headers['Content-Length'] = (string) $size;
        }

        $body = file_get_contents($this->filePath);

        if ($body === false) {
            throw new RuntimeException(sprintf('Unable to read binary file response "%s".', $this->filePath));
        }

        return [
            'status'  => $this->status,
            'headers' => $headers,
            'body'    => $body,
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $headerName) {
            if (strcasecmp($headerName, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function resolveMimeType(string $filePath): string
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        $map = [
            'css'   => 'text/css',
            'js'    => 'application/javascript',
            'mjs'   => 'application/javascript',
            'json'  => 'application/json',
            'svg'   => 'image/svg+xml',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'otf'   => 'font/otf',
            'eot'   => 'application/vnd.ms-fontobject',
            'wasm'  => 'application/wasm',
        ];

        if ($extension !== '' && isset($map[$extension])) {
            return $map[$extension];
        }

        $finfo     = new finfo(FILEINFO_MIME_TYPE);
        $detected  = $finfo->file($filePath);

        if (is_string($detected) && $detected !== '') {
            return $detected;
        }

        return 'application/octet-stream';
    }
}
