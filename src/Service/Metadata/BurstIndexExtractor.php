<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Support\ImageOrVideoSupportTrait;

use function is_string;
use function ltrim;
use function pathinfo;
use function preg_match;
use function preg_replace;

use const PATHINFO_FILENAME;

/**
 * Derives burst indices from existing metadata.
 */
final class BurstIndexExtractor implements SingleMetadataExtractorInterface
{
    use ImageOrVideoSupportTrait;

    public function supports(string $filepath, Media $media): bool
    {
        return $this->supportsImageOrVideoMime($media);
    }

    public function extract(string $filepath, Media $media): Media
    {
        $index = $this->resolveBurstIndex($media);
        if ($index !== null) {
            $media->setBurstIndex($index);
        }

        return $media;
    }

    private function resolveBurstIndex(Media $media): ?int
    {
        $burstId = $media->getBurstUuid();
        if ($burstId === null) {
            return null;
        }

        $subSec = $media->getSubSecOriginal();
        if ($subSec !== null) {
            return $subSec;
        }

        $filename = pathinfo($media->getPath(), PATHINFO_FILENAME);
        if (!is_string($filename) || $filename === '') {
            return null;
        }

        $candidate = preg_replace('~_(?:COVER|PORTRAIT)\z~i', '', $filename);
        $candidate = $candidate ?? $filename;

        if (preg_match('~(?:BURST|IMG|VID)[^0-9]*([0-9]{2,})\z~i', $candidate, $matches) === 1) {
            return $this->normaliseIndex($matches[1]);
        }

        if (preg_match('~(?:^|[-_])([0-9]{2,})\z~', $candidate, $matches) === 1) {
            return $this->normaliseIndex($matches[1]);
        }

        return null;
    }

    private function normaliseIndex(string $digits): int
    {
        $clean = ltrim($digits, '0');

        return $clean === '' ? 0 : (int) $clean;
    }
}
