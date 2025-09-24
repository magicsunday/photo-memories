<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;

/**
 * Extension of the session-based time gap strategy that adds reusable keyword filtering
 * and optional spatial radius validation for compact event clusters.
 */
abstract class AbstractFilteredTimeGapClusterStrategy extends AbstractTimeGapClusterStrategy
{
    /**
     * @return list<string>
     */
    protected function keywords(): array
    {
        return [];
    }

    final protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        if (!$this->passesContextFilters($media, $local)) {
            return false;
        }

        $keywords = $this->keywords();
        if ($keywords !== [] && !$this->mediaMatchesKeywords($media, $keywords)) {
            return false;
        }

        return true;
    }

    protected function passesContextFilters(Media $media, DateTimeImmutable $local): bool
    {
        return true;
    }

    protected function sessionRadiusMeters(): ?float
    {
        return null;
    }

    /**
     * @param list<Media> $members
     */
    protected function isSessionValid(array $members): bool
    {
        if (!parent::isSessionValid($members)) {
            return false;
        }

        $radius = $this->sessionRadiusMeters();
        if ($radius === null) {
            return true;
        }

        return $this->allWithinRadius($members, $radius);
    }
}
