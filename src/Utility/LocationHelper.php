<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\Contract\LocationLabelResolverInterface;
use MagicSunday\Memories\Utility\Contract\PoiContextAnalyzerInterface;

/**
 * Facade combining the default location label resolver and POI analyzer helpers.
 */
final readonly class LocationHelper implements LocationLabelResolverInterface, PoiContextAnalyzerInterface
{
    public function __construct(
        private LocationLabelResolverInterface $labelResolver,
        private PoiContextAnalyzerInterface $poiContextAnalyzer,
    ) {
    }

    public static function createDefault(?string $preferredLocale = null): self
    {
        $poiNormalizer    = new DefaultPoiNormalizer();
        $poiLabelResolver = new DefaultPoiLabelResolver($preferredLocale);
        $poiScorer        = new DefaultPoiScorer();
        $poiAnalyzer      = new DefaultPoiContextAnalyzer($poiNormalizer, $poiScorer, $poiLabelResolver);
        $labelResolver    = new DefaultLocationLabelResolver($poiAnalyzer, $poiLabelResolver);

        return new self($labelResolver, $poiAnalyzer);
    }

    public function localityKey(?Location $location): ?string
    {
        return $this->labelResolver->localityKey($location);
    }

    public function displayLabel(?Location $location): ?string
    {
        return $this->labelResolver->displayLabel($location);
    }

    public function localityKeyForMedia(Media $media): ?string
    {
        return $this->labelResolver->localityKeyForMedia($media);
    }

    public function labelForMedia(Media $media): ?string
    {
        return $this->labelResolver->labelForMedia($media);
    }

    public function majorityLabel(array $members): ?string
    {
        return $this->labelResolver->majorityLabel($members);
    }

    public function majorityLocationComponents(array $members): array
    {
        return $this->labelResolver->majorityLocationComponents($members);
    }

    public function sameLocality(Media $a, Media $b): bool
    {
        return $this->labelResolver->sameLocality($a, $b);
    }

    public function resolvePrimaryPoi(Location $location): ?array
    {
        return $this->poiContextAnalyzer->resolvePrimaryPoi($location);
    }

    public function bestLabelForLocation(Location $location): ?string
    {
        return $this->poiContextAnalyzer->bestLabelForLocation($location);
    }

    public function majorityPoiContext(array $members): ?array
    {
        return $this->poiContextAnalyzer->majorityPoiContext($members);
    }
}
