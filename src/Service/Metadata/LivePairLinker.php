<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Repository\MediaRepository;

use function abs;
use function array_map;
use function implode;
use function is_string;
use function sha1;
use function str_starts_with;
use function trim;
use function usort;

/**
 * Links photo/video live pairs using Apple metadata and heuristic fallbacks.
 */
final readonly class LivePairLinker implements SingleMetadataExtractorInterface
{
    public function __construct(private MediaRepository $mediaRepository)
    {
    }

    public function supports(string $filepath, Media $media): bool
    {
        $mime = $media->getMime();

        return is_string($mime) && (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/'));
    }

    public function extract(string $filepath, Media $media): Media
    {
        $this->linkApplePair($media);

        if ($media->getLivePairMedia() instanceof Media) {
            return $media;
        }

        $this->linkHeuristicPair($media);

        return $media;
    }

    private function linkApplePair(Media $media): void
    {
        $checksum = $media->getLivePairChecksum();
        if ($checksum === null || trim($checksum) === '') {
            return;
        }

        $counterpart = $this->mediaRepository->findLivePairCandidate($checksum, $media->getPath());
        if (!$counterpart instanceof Media) {
            return;
        }

        $this->establishPair($media, $counterpart, $checksum);
    }

    private function linkHeuristicPair(Media $media): void
    {
        $takenAt = $media->getTakenAt();
        $phash   = $media->getPhash();

        if (!$takenAt instanceof DateTimeImmutable || $phash === null || trim($phash) === '') {
            return;
        }

        $candidates = $this->mediaRepository->findNearestByPhash($phash, 8, 16);
        $best       = null;
        $bestScore  = null;

        foreach ($candidates as $row) {
            $candidate = $row['media'];
            if (!$candidate instanceof Media) {
                continue;
            }

            if ($candidate->getPath() === $media->getPath()) {
                continue;
            }

            if ($candidate->isVideo() === $media->isVideo()) {
                continue;
            }

            if ($candidate->isNoShow()) {
                continue;
            }

            $candidateTaken = $candidate->getTakenAt();
            if (!$candidateTaken instanceof DateTimeImmutable) {
                continue;
            }

            $timeDiff = $this->secondsBetween($takenAt, $candidateTaken);
            if ($timeDiff > 3) {
                continue;
            }

            if ($this->hasConflictingPair($media, $candidate)) {
                continue;
            }

            $distance = (int) ($row['distance'] ?? 0);
            if ($distance > 8) {
                continue;
            }

            $score = $timeDiff * 100 + $distance;
            if ($bestScore === null || $score < $bestScore) {
                $best      = $candidate;
                $bestScore = $score;
            }
        }

        if (!$best instanceof Media) {
            return;
        }

        $checksum = $media->getLivePairChecksum();
        if ($checksum === null || trim($checksum) === '') {
            $counterChecksum = $best->getLivePairChecksum();
            if ($counterChecksum === null || trim($counterChecksum) === '') {
                $checksum = $this->buildHeuristicChecksum($media, $best);
            } else {
                $checksum = $counterChecksum;
            }
        }

        $this->establishPair($media, $best, $checksum);
    }

    private function hasConflictingPair(Media $media, Media $candidate): bool
    {
        $existing = $candidate->getLivePairMedia();
        if ($existing instanceof Media && $existing !== $media) {
            return true;
        }

        $mediaChecksum     = $media->getLivePairChecksum();
        $candidateChecksum = $candidate->getLivePairChecksum();

        return $mediaChecksum !== null
            && $candidateChecksum !== null
            && trim($mediaChecksum) !== ''
            && trim($candidateChecksum) !== ''
            && $mediaChecksum !== $candidateChecksum;
    }

    private function establishPair(Media $media, Media $counterpart, string $checksum): void
    {
        if ($media->getLivePairChecksum() === null || trim($media->getLivePairChecksum()) === '') {
            $media->setLivePairChecksum($checksum);
        }

        if ($counterpart->getLivePairChecksum() === null || trim($counterpart->getLivePairChecksum()) === '') {
            $counterpart->setLivePairChecksum($checksum);
        }

        $media->setLivePairMedia($counterpart);
        if ($counterpart->getLivePairMedia() !== $media) {
            $counterpart->setLivePairMedia($media);
        }
    }

    private function buildHeuristicChecksum(Media $a, Media $b): string
    {
        $pair = [$a, $b];
        usort(
            $pair,
            static fn (Media $left, Media $right): int => $left->getId() <=> $right->getId()
        );

        $parts = array_map(static fn (Media $item): string => $item->getChecksum(), $pair);

        return sha1('heuristic-live|' . implode('|', $parts));
    }

    private function secondsBetween(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        return abs($a->getTimestamp() - $b->getTimestamp());
    }
}
