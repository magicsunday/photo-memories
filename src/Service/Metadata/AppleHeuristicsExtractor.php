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

use function array_values;
use function basename;
use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function is_string;
use function pathinfo;
use function preg_match;
use function scandir;
use function sha1;
use function sort;
use function strtolower;

use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

/**
 * Reads burst UUID & builds a live-pair checksum if possible.
 */
final class AppleHeuristicsExtractor implements SingleMetadataExtractorInterface
{
    use ImageOrVideoSupportTrait;

    public function supports(string $filepath, Media $media): bool
    {
        return $this->supportsImageOrVideoMime($media);
    }

    public function extract(string $filepath, Media $media): Media
    {
        $burst = $this->findBurstUuid($filepath);
        if ($burst !== null) {
            $media->setBurstUuid($burst);
        }

        $pair = $this->calcLivePairChecksum($filepath);
        if ($pair !== null) {
            $media->setLivePairChecksum($pair);
        }

        return $media;
    }

    private function findBurstUuid(string $path): ?string
    {
        $candidates = [$path . '.xmp', $path];
        foreach ($candidates as $f) {
            if (!is_file($f)) {
                continue;
            }

            $blob = @file_get_contents($f, false, null, 0, 512 * 1024);
            if (!is_string($blob)) {
                continue;
            }

            if ($blob === '') {
                continue;
            }

            if (preg_match('~(BurstUUID|Apple\:RunUUID)\s*["\']?[:=]\s*["\']?([0-9A-Fa-f\-]{8,})~', $blob, $m)) {
                return $m[2];
            }
        }

        return null;
    }

    private function calcLivePairChecksum(string $path): ?string
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return null;
        }

        $base = pathinfo($path, PATHINFO_FILENAME);
        if ($base === '') {
            return null;
        }

        $allowedExtensions = ['jpg', 'jpeg', 'heic', 'mov', 'mp4'];
        $targetBase        = strtolower($base);

        /** @var array<string, string> $basenames */
        $basenames = [];

        if (is_file($path)) {
            $originExt = pathinfo($path, PATHINFO_EXTENSION);
            if ($originExt !== '' && in_array(strtolower($originExt), $allowedExtensions, true)) {
                $basename             = basename($path);
                $basenames[$basename] = $basename;
            }
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = $dir . '/' . $entry;
            if (!is_file($candidate)) {
                continue;
            }

            $candidateBase = pathinfo($entry, PATHINFO_FILENAME);
            $candidateExt  = pathinfo($entry, PATHINFO_EXTENSION);
            if ($candidateBase === '' || $candidateExt === '') {
                continue;
            }

            if (strtolower($candidateBase) !== $targetBase) {
                continue;
            }

            if (!in_array(strtolower($candidateExt), $allowedExtensions, true)) {
                continue;
            }

            $basenames[$entry] = $entry;
        }

        if (count($basenames) < 2) {
            return null;
        }

        $filenames = array_values($basenames);
        sort($filenames, SORT_STRING);

        return sha1(implode('|', $filenames));
    }
}
