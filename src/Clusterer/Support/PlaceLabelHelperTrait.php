<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

/**
 * Provides a small helper for strategies that attach majority place labels to clusters.
 *
 * @property-read LocationHelper $locHelper
 */
trait PlaceLabelHelperTrait
{
    /**
     * Adds the majority place label (if any) of the given members to the provided params array.
     *
     * @param list<Media> $members
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function withMajorityPlace(array $members, array $params = []): array
    {
        $label = $this->locHelper->majorityLabel($members);

        if ($label !== null) {
            $params['place'] = $label;
        }

        return $params;
    }
}

