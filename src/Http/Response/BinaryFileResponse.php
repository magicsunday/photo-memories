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

use function array_any;
use function array_keys;
use function file_get_contents;
use function filesize;
use function header;
use function http_response_code;
use function is_file;
use function is_string;
use function pathinfo;
use function sprintf;
use function strcasecmp;
use function strtolower;

use const FILEINFO_MIME_TYPE;
use const PATHINFO_EXTENSION;

/**
 * Class BinaryFileResponse.
 */
final class BinaryFileResponse extends Response
{
    private const int DEFAULT_STATUS = 200;

    private readonly string $filePath;

    private bool $contentLoaded = false;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $filePath,
        int $status = self::DEFAULT_STATUS,
        array $headers = [],
    ) {
        if (is_file($filePath) === false) {
            throw new RuntimeException(sprintf('Binary file response path "%s" does not exist.', $filePath));
        }

        $this->filePath = $filePath;

        $resolvedHeaders = $headers;

        if ($this->hasHeader($resolvedHeaders, 'Content-Type') === false) {
            $resolvedHeaders['Content-Type'] = $this->resolveMimeType($filePath);
        }

        if ($this->hasHeader($resolvedHeaders, 'Content-Length') === false) {
            $size = filesize($filePath);

            if ($size === false) {
                throw new RuntimeException(sprintf('Unable to determine file size for "%s".', $filePath));
            }

            $resolvedHeaders['Content-Length'] = (string) $size;
        }

        parent::__construct('', $status, $resolvedHeaders);
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function send(): void
    {
        if ($this->contentLoaded) {
            parent::send();

            return;
        }

        $statusCode = $this->getStatusCode();

        http_response_code($statusCode);

        foreach ($this->getHeaders() as $name => $value) {
            header($name . ': ' . $value, true, $statusCode);
        }

        $this->sendFile($this->filePath);
    }

    public function getContent(): string
    {
        if ($this->contentLoaded === false) {
            $body = file_get_contents($this->filePath);

            if ($body === false) {
                throw new RuntimeException(sprintf('Unable to read binary file response "%s".', $this->filePath));
            }

            $this->contentLoaded = true;
            parent::setContent($body);
        }

        return parent::getContent();
    }

    public function setContent(string $content): void
    {
        $this->contentLoaded = true;
        parent::setContent($content);
    }

    /**
     * @param array<string, string> $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        return array_any(
            array_keys($headers),
            static fn (string $headerName): bool => strcasecmp($headerName, $name) === 0,
        );
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

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($filePath);

        if (is_string($detected) && $detected !== '') {
            return $detected;
        }

        return 'application/octet-stream';
    }
}
