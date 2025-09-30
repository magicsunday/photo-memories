<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing\Stage;

use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionStageInterface;
use finfo;
use Throwable;

use function in_array;
use function is_string;
use function mime_content_type;
use function pathinfo;
use function preg_match;
use function strtolower;

use const FILEINFO_MIME_TYPE;
use const PATHINFO_EXTENSION;

final class MimeDetectionStage implements MediaIngestionStageInterface
{
    /**
     * @var list<string>
     */
    private readonly array $imageExtensions;

    /**
     * @var list<string>
     */
    private readonly array $videoExtensions;

    private readonly finfo $finfo;

    /**
     * @param list<string>|null $imageExtensions
     * @param list<string>|null $videoExtensions
     */
    public function __construct(
        ?array $imageExtensions = null,
        ?array $videoExtensions = null,
    ) {
        $this->imageExtensions = $imageExtensions ?? self::defaultImageExtensions();
        $this->videoExtensions = $videoExtensions ?? self::defaultVideoExtensions();
        $this->finfo           = new finfo(FILEINFO_MIME_TYPE);
    }

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        if ($context->isSkipped()) {
            return $context;
        }

        $mime = $this->detectMime($context->getFilePath());
        $context = $context->withDetectedMime($mime);

        if ($context->isStrictMime() && $this->isMimeConsistent($context->getFilePath(), $mime) === false) {
            return $context->markSkipped();
        }

        return $context;
    }

    private function detectMime(string $path): string
    {
        $mime = '';

        try {
            $value = @$this->finfo->file($path);
            if (is_string($value) && $value !== '') {
                $mime = $value;
            }
        } catch (Throwable) {
            // Ignore and fallback to mime_content_type
        }

        if ($mime === '') {
            $value = @mime_content_type($path);
            if (is_string($value) && $value !== '') {
                $mime = $value;
            }
        }

        return $mime !== '' ? $mime : 'application/octet-stream';
    }

    private function isMimeConsistent(string $filepath, string $detectedMime): bool
    {
        if ($this->isImageExt($filepath)) {
            return preg_match('#^image/#', $detectedMime) === 1;
        }

        if ($this->isVideoExt($filepath)) {
            return preg_match('#^video/#', $detectedMime) === 1;
        }

        return true;
    }

    private function isImageExt(string $filepath): bool
    {
        $ext = strtolower((string) pathinfo($filepath, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $this->imageExtensions, true);
    }

    private function isVideoExt(string $filepath): bool
    {
        $ext = strtolower((string) pathinfo($filepath, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $this->videoExtensions, true);
    }

    /**
     * @return list<string>
     */
    private static function defaultImageExtensions(): array
    {
        return [
            'jpg', 'jpeg', 'jpe', 'jxl', 'avif', 'heic', 'heif', 'png', 'webp', 'gif', 'bmp', 'tiff', 'tif',
            'cr2', 'cr3', 'nef', 'arw', 'rw2', 'raf', 'dng',
        ];
    }

    /**
     * @return list<string>
     */
    private static function defaultVideoExtensions(): array
    {
        return [
            'mp4', 'm4v', 'mov', '3gp', '3g2', 'avi', 'mkv', 'webm',
        ];
    }
}
