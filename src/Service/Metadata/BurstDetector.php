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
use function count;
use function implode;
use function is_string;
use function sha1;
use function str_starts_with;
use function strcmp;
use function substr;
use function trim;
use function usort;

/**
 * Detects burst groups heuristically based on capture time and perceptual hash.
 */
final class BurstDetector implements SingleMetadataExtractorInterface
{
    public function __construct(private readonly MediaRepository $mediaRepository)
    {
    }

    public function supports(string $filepath, Media $media): bool
    {
        $mime = $media->getMime();

        return is_string($mime) && (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/'));
    }

    public function extract(string $filepath, Media $media): Media
    {
        if ($media->getBurstUuid() !== null) {
            return $media;
        }

        $takenAt = $media->getTakenAt();
        $phash   = $media->getPhash();

        if (!$takenAt instanceof DateTimeImmutable || $phash === null || trim($phash) === '') {
            return $media;
        }

        $members = $this->collectMembers($media, $takenAt, $phash);
        if (count($members) < 2) {
            return $media;
        }

        $sorted  = $this->sortMembers($members);
        $burstId = $this->buildBurstIdentifier($sorted);

        foreach ($sorted as $index => $member) {
            if ($member->getBurstUuid() === null) {
                $member->setBurstUuid($burstId);
            }

            if ($member->getBurstIndex() === null) {
                $member->setBurstIndex($index);
            }
        }

        return $media;
    }

    /**
     * @return list<Media>
     */
    private function collectMembers(Media $media, DateTimeImmutable $takenAt, string $phash): array
    {
        $seenPaths = [$media->getPath() => true];
        $members   = [$media];

        $candidates = $this->mediaRepository->findNearestByPhash($phash, 8, 16);
        foreach ($candidates as $candidateRow) {
            $candidate = $candidateRow['media'];
            if (!$candidate instanceof Media) {
                continue;
            }

            $path = $candidate->getPath();
            if (isset($seenPaths[$path])) {
                continue;
            }

            $candidateTaken = $candidate->getTakenAt();
            if (!$candidateTaken instanceof DateTimeImmutable) {
                continue;
            }

            if ($this->secondsBetween($takenAt, $candidateTaken) > 3) {
                continue;
            }

            if ($candidate->getBurstUuid() !== null) {
                continue;
            }

            $seenPaths[$path] = true;
            $members[]        = $candidate;
        }

        return $members;
    }

    /**
     * @param list<Media> $members
     *
     * @return list<Media>
     */
    private function sortMembers(array $members): array
    {
        usort(
            $members,
            static function (Media $left, Media $right): int {
                $leftTaken  = $left->getTakenAt();
                $rightTaken = $right->getTakenAt();

                if ($leftTaken instanceof DateTimeImmutable && $rightTaken instanceof DateTimeImmutable) {
                    $cmp = $leftTaken->getTimestamp() <=> $rightTaken->getTimestamp();
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }

                return strcmp($left->getChecksum(), $right->getChecksum());
            }
        );

        return $members;
    }

    /**
     * @param list<Media> $members
     */
    private function buildBurstIdentifier(array $members): string
    {
        $parts = array_map(
            static function (Media $item): string {
                $takenAt = $item->getTakenAt();
                $stamp   = $takenAt instanceof DateTimeImmutable ? $takenAt->format(DateTimeImmutable::ATOM) : '0';

                return $stamp . ':' . $item->getChecksum();
            },
            $members
        );

        return 'heuristic-burst-' . substr(sha1(implode('|', $parts)), 0, 16);
    }

    private function secondsBetween(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        return abs($a->getTimestamp() - $b->getTimestamp());
    }
}
