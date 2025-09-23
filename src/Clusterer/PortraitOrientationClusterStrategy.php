<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Portrait-oriented photos grouped into time sessions (no face detection).
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 45])]
final class PortraitOrientationClusterStrategy extends AbstractTimeGapClusterStrategy
{
    public function __construct(
        private readonly float $minPortraitRatio = 1.2,
        int $sessionGapSeconds = 2 * 3600,
        int $minItems = 4
    ) {
        parent::__construct('UTC', $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'portrait_orientation';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        $width = $media->getWidth();
        $height = $media->getHeight();

        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return false;
        }

        if ($height <= $width) {
            return false;
        }

        $ratio = (float) $height / (float) $width;

        return $ratio >= $this->minPortraitRatio;
    }
}
