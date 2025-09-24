<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractFilteredOverYearsStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Aggregates panoramas across years; requires per-year minimum.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 46])]
final class PanoramaOverYearsClusterStrategy extends AbstractFilteredOverYearsStrategy
{
    public function __construct(
        private readonly float $minAspect = 2.4,
        int $perYearMin = 3,
        int $minYears = 3,
        int $minItemsTotal = 15,
        string $timezone = 'UTC'
    ) {
        parent::__construct($timezone, $perYearMin, $minYears, $minItemsTotal);
    }

    public function name(): string
    {
        return 'panorama_over_years';
    }

    protected function shouldInclude(Media $media, DateTimeImmutable $local): bool
    {
        $width = $media->getWidth();
        $height = $media->getHeight();

        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return false;
        }

        if ($width <= $height) {
            return false;
        }

        $ratio = (float) $width / (float) $height;

        return $ratio >= $this->minAspect;
    }
}
