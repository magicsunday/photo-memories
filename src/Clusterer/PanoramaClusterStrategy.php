<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Clusters panorama photos (very wide aspect ratio) into time sessions.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 47])]
final class PanoramaClusterStrategy extends AbstractTimeGapClusterStrategy
{
    public function __construct(
        private readonly float $minAspect = 2.4,
        int $sessionGapSeconds = 3 * 3600,
        int $minItems = 3
    ) {
        parent::__construct('UTC', $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'panorama';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
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
