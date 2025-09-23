<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;

/**
 * Adds keyword based filtering to {@see AbstractConsecutiveRunOverYearsStrategy}.
 */
abstract class AbstractKeywordConsecutiveRunOverYearsStrategy extends AbstractConsecutiveRunOverYearsStrategy
{
    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        return $this->mediaMatchesKeywords($media, $this->keywords());
    }

    /**
     * @return list<string>
     */
    abstract protected function keywords(): array;
}
