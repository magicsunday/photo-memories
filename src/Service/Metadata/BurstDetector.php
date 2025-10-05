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
use DateTimeInterface;
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
final readonly class BurstDetector implements SingleMetadataExtractorInterface
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
        $existingBurst = $media->getBurstUuid();
        if ($existingBurst !== null && $media->isBurstRepresentative() !== null) {
            return $media;
        }

        if ($existingBurst !== null) {
            $members = $this->collectExistingMembers($media, $existingBurst);
            $this->assignRepresentatives($members);

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

        $this->assignRepresentatives($sorted);

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
     * @return list<Media>
     */
    private function collectExistingMembers(Media $media, string $burstUuid): array
    {
        $members = [$media];

        foreach ($this->mediaRepository->findBurstMembers($burstUuid, $media->getPath()) as $sibling) {
            if (!$sibling instanceof Media) {
                continue;
            }

            $members[] = $sibling;
        }

        return $this->sortMembers($members);
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
                $stamp   = $takenAt instanceof DateTimeImmutable ? $takenAt->format(DateTimeInterface::ATOM) : '0';

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

    /**
     * @param list<Media> $members
     */
    private function assignRepresentatives(array $members): void
    {
        if ($members === []) {
            return;
        }

        $representative = $this->selectRepresentative($members);

        foreach ($members as $member) {
            $member->setBurstRepresentative($member === $representative);
        }
    }

    /**
     * @param list<Media> $members
     */
    private function selectRepresentative(array $members): Media
    {
        $best = $members[0];

        foreach ($members as $candidate) {
            if ($candidate === $best) {
                continue;
            }

            if ($this->isBetterRepresentative($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function isBetterRepresentative(Media $candidate, Media $current): bool
    {
        $candidateSharpness = $this->clamp01($candidate->getSharpness());
        $currentSharpness   = $this->clamp01($current->getSharpness());

        if ($candidateSharpness !== null || $currentSharpness !== null) {
            if ($candidateSharpness === null) {
                return false;
            }

            if ($currentSharpness === null) {
                return true;
            }

            if ($candidateSharpness !== $currentSharpness) {
                return $candidateSharpness > $currentSharpness;
            }
        }

        $candidateIndex = $candidate->getBurstIndex();
        $currentIndex   = $current->getBurstIndex();

        if ($candidateIndex !== null && $currentIndex !== null && $candidateIndex !== $currentIndex) {
            return $candidateIndex < $currentIndex;
        }

        $candidateTaken = $candidate->getTakenAt();
        $currentTaken   = $current->getTakenAt();

        if ($candidateTaken instanceof DateTimeImmutable && $currentTaken instanceof DateTimeImmutable) {
            $candidateStamp = $candidateTaken->getTimestamp();
            $currentStamp   = $currentTaken->getTimestamp();

            if ($candidateStamp !== $currentStamp) {
                return $candidateStamp < $currentStamp;
            }
        }

        return strcmp($candidate->getChecksum(), $current->getChecksum()) < 0;
    }

    private function clamp01(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}
