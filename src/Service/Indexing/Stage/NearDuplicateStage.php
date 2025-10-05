<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing\Stage;

use InvalidArgumentException;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Repository\MediaDuplicateRepository;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionStageInterface;

use function is_int;

/**
 * Detects perceptually similar media and records their Hamming distance.
 */
final readonly class NearDuplicateStage implements MediaIngestionStageInterface
{
    public function __construct(
        private MediaRepository          $mediaRepository,
        private MediaDuplicateRepository $duplicateRepository,
        private int                      $maxHammingDistance = 6,
    ) {
        if ($this->maxHammingDistance < 0) {
            throw new InvalidArgumentException('Maximum Hamming distance must be zero or greater.');
        }
    }

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        if ($context->isSkipped()) {
            return $context;
        }

        $media = $context->getMedia();
        if (!$media instanceof Media) {
            return $context;
        }

        $phash = $media->getPhash();
        if ($phash === null || $phash === '') {
            return $context;
        }

        $candidates = $this->mediaRepository->findNearestByPhash($phash, $this->maxHammingDistance);
        if ($candidates === []) {
            return $context;
        }

        foreach ($candidates as $candidate) {
            $other    = $candidate['media'] ?? null;
            $distance = $candidate['distance'] ?? null;

            if (!$other instanceof Media) {
                continue;
            }

            if ($other === $media) {
                continue;
            }

            if (!is_int($distance)) {
                continue;
            }

            if ($distance > $this->maxHammingDistance) {
                continue;
            }

            if ($context->isDryRun()) {
                continue;
            }

            $this->duplicateRepository->recordDistance($media, $other, $distance);
        }

        return $context;
    }
}
