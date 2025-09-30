<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Http\Response;

use function basename;
use function filesize;
use function finfo_close;
use function finfo_file;
use function finfo_open;
use function is_file;
use function is_string;
use function sprintf;

use const FILEINFO_MIME_TYPE;

/**
 * Minimal binary file response.
 */
final class BinaryFileResponse extends Response
{
    private string $filePath;

    public function __construct(string $filePath, int $statusCode = 200, array $headers = [])
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException(sprintf('File "%s" cannot be read.', $filePath));
        }

        $this->filePath = $filePath;
        parent::__construct('', $statusCode, $headers);
    }

    public function send(): void
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo !== false ? finfo_file($finfo, $this->filePath) : null;
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        if (is_string($mime) && $mime !== '') {
            $this->setHeader('Content-Type', $mime);
        }

        $this->setHeader('Content-Length', (string) filesize($this->filePath));
        $this->setHeader('Content-Disposition', 'inline; filename="' . basename($this->filePath) . '"');

        parent::send();
        parent::sendFile($this->filePath);
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
