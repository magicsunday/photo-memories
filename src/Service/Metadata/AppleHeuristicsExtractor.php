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

use function array_map;
use function basename;
use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function is_file;
use function is_string;
use function pathinfo;
use function preg_match;
use function sha1;
use function str_starts_with;

use const PATHINFO_FILENAME;

/**
 * Reads burst UUID & builds a live-pair checksum if possible.
 */
final class AppleHeuristicsExtractor implements SingleMetadataExtractorInterface
{
    public function supports(string $filepath, Media $media): bool
    {
        $mime = $media->getMime();

        return $mime !== null && (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/'));
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
        $dir   = dirname($path);
        $base  = pathinfo($path, PATHINFO_FILENAME);
        $cands = [
            $dir . '/' . $base . '.mov',
            $dir . '/' . $base . '.MP4',
            $dir . '/' . $base . '.heic',
            $dir . '/' . $base . '.HEIC',
        ];
        /** @var list<string> $have */
        $have = [];
        foreach ($cands as $c) {
            if (is_file($c)) {
                $have[] = $c;
            }
        }

        if (count($have) < 2) {
            return null;
        }

        return sha1(implode('|', array_map(static fn (string $f): string => basename($f), $have)));
    }
}
